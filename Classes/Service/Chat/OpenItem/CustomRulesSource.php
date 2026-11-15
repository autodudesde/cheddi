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

namespace AutoDudes\Cheddi\Service\Chat\OpenItem;

use AutoDudes\AiSuite\Domain\Repository\PagesRepository;
use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\AiSuite\Service\MetadataService;
use AutoDudes\Cheddi\Domain\Model\Dto\OpenItemCandidate;
use AutoDudes\Cheddi\Domain\Repository\OpenItemRepository;
use AutoDudes\Cheddi\Domain\Repository\OpenItemRuleRepository;
use AutoDudes\Cheddi\Enumeration\OpenItemRuleProblemEnumeration;
use AutoDudes\Cheddi\Service\Chat\OpenItem\Fix\OpenItemFixTarget;
use AutoDudes\Cheddi\Service\Chat\OpenItem\Fix\OpenItemFixTargets;
use AutoDudes\Cheddi\Service\Chat\OpenItem\RuleSource\OpenItemRuleSources;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class CustomRulesSource implements OpenItemSource
{
    public const KIND = 'rule';
    public const MAX_MATCHES_PER_RULE = 1000;

    private const MESSAGE_MAX_LENGTH = 255;
    private const SEVERITIES = ['notice', 'warning', 'error'];
    private const LABEL_SUBGROUP = 'LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.openItems.subgroup';

    /**
     * @var array<int, null|array<string, mixed>>
     */
    private array $rules = [];

    public function __construct(
        private readonly OpenItemRuleRepository $ruleRepository,
        private readonly OpenItemRuleValidator $validator,
        private readonly OpenItemRuleSources $ruleSources,
        private readonly PagesRepository $pagesRepository,
        private readonly OpenItemRepository $openItems,
        private readonly OpenItemFixTargets $fixTargets,
        private readonly LoggerInterface $logger,
        private readonly LocalizationService $localizationService,
        private readonly OpenItemPresetCatalog $presetCatalog,
    ) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function collect(int $now): iterable
    {
        foreach ($this->ruleRepository->findActive() as $rule) {
            yield from $this->collectRule($rule, $now);
        }
    }

    public function describe(array $row, string $pageTitle): array
    {
        $rule = $this->ruleOf($row['item_key']);
        $sourceIdentifier = (string) ($rule['source'] ?? '');
        $source = '' === $sourceIdentifier ? null : $this->ruleSources->get($sourceIdentifier);
        $subgroup = $source?->subgroupOf($row);
        $title = $this->presetCatalog->displayTitle($rule ?? []);
        if (null !== $subgroup) {
            return [
                'group' => $this->localizationService->translate(self::LABEL_SUBGROUP, [$title, $subgroup['label']]),
                'label' => '',
            ];
        }

        return [
            'group' => $title,
            'label' => null === $source ? $row['message'] : $source->describeMatch($row),
        ];
    }

    /**
     * @param array{item_key: string, message: string, page_uid: int} $row
     */
    public function groupKeyOf(array $row): string
    {
        $key = implode(':', array_slice(explode(':', $row['item_key']), 0, 2));
        $sourceIdentifier = (string) ($this->ruleOf($row['item_key'])['source'] ?? '');
        $subgroup = '' === $sourceIdentifier ? null : $this->ruleSources->get($sourceIdentifier)?->subgroupOf($row);

        return null === $subgroup ? $key : $key.':'.$subgroup['key'];
    }

    public static function keyPrefix(int $ruleUid): string
    {
        return sprintf('%s:%d:', self::KIND, $ruleUid);
    }

    /**
     * @param array<string, mixed> $rule
     *
     * @return list<OpenItemCandidate>
     */
    public function collectRule(array $rule, int $now): array
    {
        $ruleUid = (int) $rule['uid'];
        $problem = $this->validator->problemOf($rule);
        if (null !== $problem) {
            return $this->keepEarlierItems($ruleUid, $problem, $now);
        }

        try {
            $matches = $this->matchesOf($rule, $now, self::MAX_MATCHES_PER_RULE + 1);
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: an open item rule failed', ['rule' => $ruleUid, 'exception' => $e->getMessage()]);

            return $this->keepEarlierItems($ruleUid, OpenItemRuleProblemEnumeration::FAILED, $now);
        }

        if (count($matches) > self::MAX_MATCHES_PER_RULE) {
            return $this->keepEarlierItems($ruleUid, OpenItemRuleProblemEnumeration::TOO_MANY_MATCHES, $now);
        }

        if (OpenItemFixTarget::KIND_IMAGE === ($this->fixTargets->fixOfRule($rule)['kind'] ?? '')) {
            $matches = array_values(array_filter($matches, fn (array $match): bool => in_array($this->fileOf($match)['mime_type'] ?? '', MetadataService::SUPPORTED_IMAGE_MIME_TYPES, true)));
        }

        $this->ruleRepository->recordStatus($ruleUid, '');
        $severity = in_array($rule['severity'], self::SEVERITIES, true) ? (string) $rule['severity'] : 'notice';

        return array_map(fn (array $match): OpenItemCandidate => new OpenItemCandidate(
            $match['pageUid'],
            self::keyPrefix($ruleUid).$match['key'],
            $severity,
            mb_substr($this->labelOf($match), 0, self::MESSAGE_MAX_LENGTH),
            $match['tstamp'],
            $match['recordTable'],
            $match['recordUid'],
            (string) ($rule['area'] ?? ''),
        ), $matches);
    }

    /**
     * @param array<string, mixed> $rule
     *
     * @return list<array{pageUid: int, key: string, label: string, tstamp: int, recordTable: string, recordUid: int}>
     */
    public function matchesOf(array $rule, int $now, int $limit): array
    {
        $sourceIdentifier = (string) ($rule['source'] ?? '');
        if ('' === $sourceIdentifier) {
            $table = (string) $rule['source_table'];

            return array_map(static fn (array $match): array => [
                'pageUid' => 'pages' === $table ? $match['uid'] : $match['pid'],
                'key' => sprintf('%s:%d', $table, $match['uid']),
                'label' => $match['label'],
                'tstamp' => $match['tstamp'],
                'recordTable' => $table,
                'recordUid' => $match['uid'],
            ], $this->ruleRepository->findMatches($rule, $now, $limit));
        }

        $source = $this->ruleSources->get($sourceIdentifier);
        if (null === $source) {
            return [];
        }

        $scope = (int) ($rule['root_page'] ?? 0) > 0 ? array_flip($this->pagesRepository->getSubtreePageIds((int) $rule['root_page'])) : null;
        $matches = [];
        foreach ($source->matches(OpenItemRuleSources::parametersOf($rule), $now, $limit * 4) as $match) {
            if (null === $scope || isset($scope[$match['pageUid']])) {
                $matches[] = $match;
            }
            if (count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    /**
     * @param array{item_key: string} $row
     */
    public function mayShow(array $row, BackendUserAuthentication $backendUser): bool
    {
        $sourceIdentifier = (string) ($this->ruleOf($row['item_key'])['source'] ?? '');
        if ('' === $sourceIdentifier) {
            return true;
        }

        $source = $this->ruleSources->get($sourceIdentifier);

        return null !== $source && $source->mayUse($backendUser);
    }

    /**
     * @param array{recordTable: string, recordUid: int} $match
     *
     * @return null|array<string, mixed>
     */
    private function fileOf(array $match): ?array
    {
        $fileField = OpenItemFixTargets::IMAGE_TABLES[$match['recordTable']] ?? null;
        if (null === $fileField || $match['recordUid'] <= 0) {
            return null;
        }
        $reference = BackendUtility::getRecord($match['recordTable'], $match['recordUid'], $fileField);

        return BackendUtility::getRecord('sys_file', (int) ($reference[$fileField] ?? 0), 'name,mime_type');
    }

    /**
     * @param array{label: string, recordTable: string, recordUid: int} $match
     */
    private function labelOf(array $match): string
    {
        $label = trim($match['label']);
        if ('' !== $label || '' === $match['recordTable'] || $match['recordUid'] <= 0) {
            return $label;
        }

        if (isset(OpenItemFixTargets::IMAGE_TABLES[$match['recordTable']])) {
            return (string) ($this->fileOf($match)['name'] ?? '');
        }

        $record = BackendUtility::getRecord($match['recordTable'], $match['recordUid']);

        return null === $record ? '' : trim(BackendUtility::getRecordTitle($match['recordTable'], $record));
    }

    /**
     * @return list<OpenItemCandidate>
     */
    private function keepEarlierItems(int $ruleUid, string $problem, int $now): array
    {
        $this->ruleRepository->recordStatus($ruleUid, $problem);
        $this->openItems->touchByKeyPrefix(self::keyPrefix($ruleUid), $now);

        return [];
    }

    /**
     * @return null|array<string, mixed>
     */
    private function ruleOf(string $itemKey): ?array
    {
        $ruleUid = (int) (explode(':', $itemKey)[1] ?? 0);
        if (!array_key_exists($ruleUid, $this->rules)) {
            $this->rules[$ruleUid] = $this->ruleRepository->findRule($ruleUid);
        }

        return $this->rules[$ruleUid];
    }
}
