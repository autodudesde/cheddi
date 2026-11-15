<?php

declare(strict_types=1);

/*
 *
 * This file is part of the "cheddi" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *
 */

namespace AutoDudes\Cheddi\Service\Chat\OpenItem\Fix;

use AutoDudes\AiSuite\Domain\Model\Dto\ProvenanceContext;
use AutoDudes\AiSuite\Exception\FetchedContentFailedException;
use AutoDudes\AiSuite\Factory\SettingsFactory;
use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuite\Service\MetadataService;
use AutoDudes\AiSuite\Service\ModelService;
use AutoDudes\AiSuite\Service\ProvenanceCaptureService;
use AutoDudes\AiSuite\Service\SendRequestService;
use AutoDudes\AiSuiteMcp\Mcp\Service\TcaLabelService;
use AutoDudes\Cheddi\Domain\Model\Dto\ChatToolContext;
use AutoDudes\Cheddi\Domain\Repository\OpenItemRepository;
use AutoDudes\Cheddi\Service\Chat\ChatGuidelinesService;
use AutoDudes\Cheddi\Service\Chat\GdprModelPolicy;
use AutoDudes\Cheddi\Service\Chat\OpenItemService;
use AutoDudes\Cheddi\Service\Chat\ToolBridge;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

class OpenItemFixService
{
    private const ENDPOINT = 'fieldSuggestion';
    private const ANSWER_TYPE = 'FieldSuggestion';
    private const DEFAULT_MAX_LENGTH = 300;
    private const CLIENT = 'cheddi';

    public function __construct(
        private readonly OpenItemRepository $openItems,
        private readonly OpenItemService $openItemService,
        private readonly OpenItemFixTargets $targets,
        private readonly OpenItemFixModels $models,
        private readonly SendRequestService $sendRequestService,
        private readonly MetadataService $metadataService,
        private readonly ModelService $modelService,
        private readonly SettingsFactory $settingsFactory,
        private readonly GdprModelPolicy $gdprModelPolicy,
        private readonly ChatGuidelinesService $guidelines,
        private readonly TcaLabelService $tcaLabels,
        private readonly SiteFinder $siteFinder,
        private readonly ToolBridge $toolBridge,
        private readonly ProvenanceCaptureService $provenanceCapture,
        private readonly BackendUserService $backendUserService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{suggestion?: string, model?: string, base?: string, usage?: array<string, mixed>, error?: string}
     */
    public function suggest(int $itemUid, string $model): array
    {
        $target = $this->editableTarget($itemUid);
        if (null === $target) {
            return ['error' => 'notFound'];
        }
        if (OpenItemFixTarget::KIND_IMAGE === $target->kind) {
            $model = (string) ($this->models->imageModel()['name'] ?? '');
            if ('' === $model) {
                return ['error' => 'modelNotAllowed'];
            }
        } elseif (!$this->models->isAllowedTextModel($model)) {
            return ['error' => 'modelNotAllowed'];
        }

        $extConf = $this->settingsFactory->mergeExtConfAndUserGroupSettings();
        $languageUid = $this->languageUidOf($target);
        $language = $this->languageOf($target->pageUid, $languageUid);

        try {
            $content = OpenItemFixTarget::KIND_IMAGE === $target->kind
                ? $this->metadataService->getFileContent($target->fileUid)
                : $this->metadataService->fetchPageContent($target->pageUid, $languageUid);
        } catch (FetchedContentFailedException $e) {
            $this->logger->warning('ChEddi: the page content for a field suggestion could not be fetched', ['item' => $itemUid, 'exception' => $e->getMessage()]);
            $content = '';
        }
        if ('' === trim($content)) {
            return ['error' => 'noContent'];
        }

        $answer = $this->sendRequestService->sendDataRequest(self::ENDPOINT, [
            'kind' => $target->kind,
            'model' => $model,
            'keys' => OpenItemFixTarget::KIND_TEXT === $target->kind ? $this->modelService->fetchKeysByModel($extConf, [$model]) : [],
            'gdprOnly' => $this->gdprModelPolicy->isForced($extConf) ? '1' : '0',
            'table' => $target->table,
            'field' => $target->field,
            'field_label' => $this->tcaLabels->getFieldLabel($target->table, $target->field),
            'max_length' => $this->maxLength($target),
            'request_content' => $content,
            'guidelines' => $this->guidelinesOf($target->pageUid),
        ], '', $language);

        if (self::ANSWER_TYPE !== $answer->getType()) {
            $this->logger->warning('ChEddi: a field suggestion failed', ['item' => $itemUid, 'type' => $answer->getType()]);

            return ['error' => 'serverError'];
        }
        $data = $answer->getResponseData();

        return [
            'suggestion' => (string) ($data['suggestion'] ?? ''),
            'model' => (string) ($data['model'] ?? $model),
            'base' => $this->currentValue($target),
            'usage' => is_array($data['usage'] ?? null) ? $data['usage'] : [],
        ];
    }

    /**
     * @return array{accepted: bool, error?: string}
     */
    public function accept(ServerRequestInterface $request, int $itemUid, string $value, string $model, string $base): array
    {
        $target = $this->editableTarget($itemUid);
        if (null === $target) {
            return ['accepted' => false, 'error' => 'notFound'];
        }
        if ('' === trim($value)) {
            return ['accepted' => false, 'error' => 'emptyValue'];
        }
        if ($this->currentValue($target) !== $base) {
            return ['accepted' => false, 'error' => 'changedMeanwhile'];
        }

        $this->provenanceCapture->begin(ProvenanceContext::generated(ProvenanceContext::FEATURE_CHAT, $model, self::CLIENT));

        try {
            $result = $this->toolBridge->execute('writeRecords', [
                'records' => [['table' => $target->table, 'uid' => $target->uid, 'fields' => [$target->field => $value]]],
            ], new ChatToolContext($request, $model));
        } finally {
            $this->provenanceCapture->end();
        }
        if (true === ($result['isError'] ?? false)) {
            $this->logger->warning('ChEddi: writing a field suggestion failed', ['item' => $itemUid, 'answer' => mb_substr((string) ($result['content'] ?? ''), 0, 300)]);

            return ['accepted' => false, 'error' => 'writeFailed'];
        }

        $this->openItemService->resolve($itemUid, OpenItemService::RESOLVED_ACCEPTED);

        return ['accepted' => true];
    }

    private function editableTarget(int $itemUid): ?OpenItemFixTarget
    {
        $row = $this->openItems->findItem($itemUid);
        $backendUser = $this->backendUserService->getBackendUser();
        if (null === $row || null === $backendUser || !$this->openItemService->isVisible($row)) {
            return null;
        }
        $target = $this->targets->targetOf($row);
        if (null === $target || !$backendUser->check('tables_modify', $target->table)) {
            return null;
        }

        return $target;
    }

    private function currentValue(OpenItemFixTarget $target): string
    {
        $record = BackendUtility::getRecordWSOL($target->table, $target->uid, $target->field);

        return (string) ($record[$target->field] ?? '');
    }

    private function maxLength(OpenItemFixTarget $target): int
    {
        $max = (int) ($GLOBALS['TCA'][$target->table]['columns'][$target->field]['config']['max'] ?? 0);

        return $max > 0 ? $max : self::DEFAULT_MAX_LENGTH;
    }

    private function languageUidOf(OpenItemFixTarget $target): int
    {
        $languageField = (string) ($GLOBALS['TCA'][$target->table]['ctrl']['languageField'] ?? '');
        if ('' === $languageField) {
            return 0;
        }
        $record = BackendUtility::getRecord($target->table, $target->uid, $languageField);

        return max(0, (int) ($record[$languageField] ?? 0));
    }

    private function languageOf(int $pageUid, int $languageUid): string
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageUid);
        } catch (SiteNotFoundException) {
            return '';
        }

        try {
            $language = $site->getLanguageById($languageUid);
        } catch (\InvalidArgumentException) {
            $language = $site->getDefaultLanguage();
        }

        return $language->getLocale()->getLanguageCode();
    }

    private function guidelinesOf(int $pageUid): string
    {
        $guidelines = $this->guidelines->forPage($pageUid);
        if (!$guidelines['resolved']) {
            return '';
        }

        return implode("\n\n", array_map(static fn (array $section): string => $section['label'].': '.$section['text'], $guidelines['sections']));
    }
}
