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

namespace AutoDudes\Cheddi\Service\Chat;

use AutoDudes\AiSuite\Enumeration\GenerationLibraryEnumeration;
use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\AiSuite\Service\SendRequestService;
use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use AutoDudes\AiSuiteMcp\Domain\Repository\SysFileReferenceRepository;
use AutoDudes\AiSuiteMcp\Mcp\Service\MediaReferenceSource;
use AutoDudes\AiSuiteMcp\Mcp\Service\RecordAccessService;
use AutoDudes\AiSuiteMcp\Mcp\Service\RecordPreviewService;
use AutoDudes\AiSuiteMcp\Mcp\Service\TcaLabelService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\Record\BulkReplaceTextTool;
use TYPO3\CMS\Core\Site\SiteFinder;

class ToolPreviewBuilder
{
    private const LLL = 'LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.toolPreview.';

    private const MAX_RECORDS = 10;

    private const MAX_VALUE_LENGTH = 200;

    private const AUDIT_TYPES = [
        'auditSeo' => 'seo',
        'auditAccessibility' => 'accessibility',
        'auditContent' => 'content',
    ];

    public function __construct(
        private readonly RecordPreviewService $recordPreview,
        private readonly RecordAccessService $recordAccess,
        private readonly RecordRepository $recordRepository,
        private readonly TcaLabelService $tcaLabels,
        private readonly LocalizationService $localizationService,
        private readonly BulkReplaceTextTool $bulkReplaceText,
        private readonly SiteFinder $siteFinder,
        private readonly SendRequestService $sendRequestService,
        private readonly FolderLabelService $folderLabels,
        private readonly MediaReferenceSource $mediaReferenceSource,
        private readonly SysFileReferenceRepository $fileReferenceRepository,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     *
     * @return null|array<string, mixed>
     */
    public function build(string $toolName, array $arguments): ?array
    {
        return match ($toolName) {
            'bulkReplaceText' => $this->bulkReplace($arguments),
            'copyMediaReference' => $this->copyReference($arguments),
            'replaceMediaReference' => $this->replaceReference($arguments),
            'translatePage' => $this->translation('pages', $this->intArgument($arguments, 'pageId'), $arguments),
            'translateRecord' => $this->translation((string) ($arguments['table'] ?? ''), $this->intArgument($arguments, 'uid'), $arguments),
            'auditSeo', 'auditAccessibility', 'auditContent' => $this->audit($toolName, $arguments),
            'generateFileMetadata' => $this->fileMetadataGeneration($arguments),
            'generateImage' => $this->imageGeneration($arguments),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function copyReferenceRefusal(array $arguments): ?string
    {
        $sourceTable = (string) ($arguments['sourceTable'] ?? '');
        $sourceUid = $this->intArgument($arguments, 'sourceUid');
        $sourceField = (string) ($arguments['sourceField'] ?? '');
        $targetTable = (string) ($arguments['targetTable'] ?? '');
        $targetUid = $this->intArgument($arguments, 'targetUid');
        $targetField = (string) ($arguments['targetField'] ?? '');
        if ('' === $sourceTable || $sourceUid <= 0 || '' === $targetTable || $targetUid <= 0 || '' === $targetField) {
            return null;
        }

        try {
            $target = $this->recordAccess->assertRecordReadAccess($targetTable, $targetUid);
        } catch (\Throwable) {
            return null;
        }
        $targetPid = 'pages' === $targetTable ? $targetUid : (int) ($target['pid'] ?? 0);

        $refusal = $this->mediaReferenceSource->fileAsSourceRefusal($sourceTable, $sourceUid, $targetTable, $targetUid, $targetField, $targetPid);
        if (null !== $refusal) {
            return $refusal;
        }
        if ([] === $this->fileReferenceRepository->findReferences($sourceTable, $sourceUid, $sourceField)) {
            return sprintf(MediaReferenceSource::NO_REFERENCE, $sourceTable, $sourceUid, $sourceField);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return null|array<string, mixed>
     */
    private function bulkReplace(array $arguments): ?array
    {
        $table = (string) ($arguments['childTable'] ?? '');
        $field = (string) ($arguments['field'] ?? '');
        if ('' === $table || '' === $field) {
            return null;
        }

        $planned = $this->bulkReplaceText->plannedChanges($arguments);
        if ([] === $planned) {
            return $this->wrap([[
                'action' => 'skipped',
                'tableLabel' => $this->tcaLabels->getTableLabel($table),
                'note' => $this->label('bulk.nothingFound'),
            ]], 1);
        }

        $records = array_map(
            static fn (array $change): array => ['table' => $table, 'uid' => $change['uid'], 'fields' => [$field => $change['value']]],
            array_slice($planned, 0, self::MAX_RECORDS),
        );

        return $this->wrap($this->recordPreview->describeWrite($records, self::MAX_VALUE_LENGTH), count($planned));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return null|array<string, mixed>
     */
    private function copyReference(array $arguments): ?array
    {
        $sourceTable = (string) ($arguments['sourceTable'] ?? '');
        $sourceUid = $this->intArgument($arguments, 'sourceUid');
        $targetTable = (string) ($arguments['targetTable'] ?? '');
        $targetUid = $this->intArgument($arguments, 'targetUid');
        $targetField = (string) ($arguments['targetField'] ?? '');
        if ('' === $sourceTable || $sourceUid <= 0 || '' === $targetTable || $targetUid <= 0 || '' === $targetField) {
            return null;
        }

        $refusal = $this->copyReferenceRefusal($arguments);
        if (null !== $refusal) {
            $record = $this->recordPreview->describeExisting($targetTable, $targetUid, 'update');
            $record['action'] = 'invalid';
            $record['note'] = $refusal;

            return $this->wrap([$record], 1);
        }

        $current = $this->fileNames($targetTable, $targetUid, $targetField);
        $copied = $this->fileNames($sourceTable, $sourceUid, (string) ($arguments['sourceField'] ?? ''));
        $source = $this->recordPreview->describeExisting($sourceTable, $sourceUid, 'target');

        $record = $this->recordPreview->describeExisting($targetTable, $targetUid, 'update');
        $record['fields'] = [[
            'name' => $targetField,
            'label' => $this->tcaLabels->getFieldLabel($targetTable, $targetField),
            'old' => implode(', ', $current),
            'new' => implode(', ', [...$current, ...$copied]),
            'truncated' => false,
        ]];
        $sourceLabel = (string) ($source['recordLabel'] ?? '');
        if ('' !== $sourceLabel) {
            $record['note'] = $this->label('media.copiedFrom', [$sourceLabel]);
        }

        return $this->wrap([$record], 1);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return null|array<string, mixed>
     */
    private function replaceReference(array $arguments): ?array
    {
        $table = (string) ($arguments['table'] ?? '');
        $uid = $this->intArgument($arguments, 'uid');
        $field = (string) ($arguments['field'] ?? '');
        $fileUid = $this->intArgument($arguments, 'fileUid');
        if ('' === $table || $uid <= 0 || '' === $field || $fileUid <= 0) {
            return null;
        }

        $record = $this->recordPreview->describeExisting($table, $uid, 'update');
        $record['fields'] = [[
            'name' => $field,
            'label' => $this->tcaLabels->getFieldLabel($table, $field),
            'old' => implode(', ', $this->fileNames($table, $uid, $field)),
            'new' => $this->fileName($fileUid),
            'truncated' => false,
        ]];
        $record['note'] = $this->label('media.replaceKeeps');

        return $this->wrap([$record], 1);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return null|array<string, mixed>
     */
    private function translation(string $table, int $uid, array $arguments): ?array
    {
        if ('' === $table || $uid <= 0) {
            return null;
        }

        $record = $this->recordPreview->describeExisting($table, $uid, 'localize');
        $pageId = 'pages' === $table ? $uid : (int) ($record['pid'] ?? 0);
        $code = (string) ($arguments['targetLanguage'] ?? '');
        $model = trim((string) ($arguments['model'] ?? ''));

        $fields = [$this->infoField('language', $this->languageTitle($pageId, $code))];
        if ('pages' === $table) {
            $fields[] = $this->infoField('scope', $this->label('translation.scopePage', [$this->recordRepository->countRecordsOnPage('tt_content', $uid)]));
        }
        $fields[] = $this->infoField('visibility', $this->label(false === ($arguments['hidden'] ?? true) ? 'translation.visible' : 'translation.hidden'));
        $fields[] = $this->infoField('translator', '' === $model ? $this->label('translation.byChat') : $this->label('translation.byModel', [$model]));
        $record['fields'] = $fields;

        return $this->wrap([$record], 1);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function audit(string $toolName, array $arguments): array
    {
        $pageId = $this->intArgument($arguments, 'pageId');
        $url = trim((string) ($arguments['url'] ?? ''));
        $type = self::AUDIT_TYPES[$toolName];

        $record = $pageId > 0
            ? $this->recordPreview->describeExisting('pages', $pageId, 'audit')
            : ['action' => 'audit', 'tableLabel' => $this->label('audit.url'), 'recordLabel' => mb_substr($url, 0, self::MAX_VALUE_LENGTH)];
        $record['fields'] = [$this->infoField('auditType', $this->label('audit.type.'.$type))];
        $record['note'] = $this->label('audit.writesNothing');

        return $this->wrap([$record], 1);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return null|array<string, mixed>
     */
    private function fileMetadataGeneration(array $arguments): ?array
    {
        $fileUid = $this->intArgument($arguments, 'fileUid');
        $model = trim((string) ($arguments['model'] ?? ''));
        if ($fileUid <= 0 || '' === $model) {
            return null;
        }

        $requested = is_array($arguments['fields'] ?? null) ? $arguments['fields'] : ['alternative', 'title'];
        $fieldLabels = array_map(
            fn (mixed $field): string => $this->tcaLabels->getFieldLabel('sys_file_metadata', (string) $field),
            array_values(array_filter($requested, 'is_string')),
        );
        $record = ['action' => 'generate', 'tableLabel' => $this->label('generate.file'), 'recordLabel' => $this->fileName($fileUid)];
        $record['fields'] = [
            $this->infoField('generates', implode(', ', $fieldLabels)),
            ...$this->modelFields(GenerationLibraryEnumeration::METADATA, 'createMetadata', 'text', $model),
        ];
        $record['note'] = $this->label('generate.suggestionsOnly');

        return $this->wrap([$record], 1);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return null|array<string, mixed>
     */
    private function imageGeneration(array $arguments): ?array
    {
        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        $model = trim((string) ($arguments['model'] ?? ''));
        if ('' === $prompt || '' === $model) {
            return null;
        }

        $record = ['action' => 'generate', 'tableLabel' => $this->label('generate.image'), 'recordLabel' => mb_substr($prompt, 0, self::MAX_VALUE_LENGTH)];
        $record['fields'] = [
            ...$this->modelFields(GenerationLibraryEnumeration::IMAGE, 'createImage', 'image', $model),
            $this->infoField('size', (string) ($arguments['size'] ?? '1024x1024')),
            $this->infoField('folder', $this->folderLabels->readable((string) ($arguments['targetFolder'] ?? '1:/user_upload/'))),
        ];

        return $this->wrap([$record], 1);
    }

    /**
     * @return list<array{name: string, label: string, old: null, new: string, truncated: bool}>
     */
    private function modelFields(string $libraryType, string $endpoint, string $keyType, string $model): array
    {
        $library = $this->libraryOf($libraryType, $endpoint, $keyType, $model);
        $name = trim((string) ($library['name'] ?? ''));
        $fields = [$this->infoField('model', '' === $name ? $model : $name)];
        $weight = (int) ($library['weight'] ?? 0);
        if ($weight > 0) {
            $fields[] = $this->infoField('cost', $this->label('generate.credits', [$weight]));
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function libraryOf(string $libraryType, string $endpoint, string $keyType, string $model): array
    {
        try {
            $answer = $this->sendRequestService->sendLibrariesRequest($libraryType, $endpoint, [$keyType]);
        } catch (\Throwable) {
            return [];
        }
        if ('Error' === $answer->getType()) {
            return [];
        }
        $libraries = $answer->getResponseData()[$keyType.'GenerationLibraries'] ?? [];
        foreach (is_array($libraries) ? $libraries : [] as $library) {
            if (is_array($library) && ($library['model_identifier'] ?? null) === $model) {
                return $library;
            }
        }

        return [];
    }

    /**
     * @return array{name: string, label: string, old: null, new: string, truncated: bool}
     */
    private function infoField(string $name, string $value): array
    {
        return ['name' => $name, 'label' => $this->label('field.'.$name), 'old' => null, 'new' => $value, 'truncated' => false];
    }

    /**
     * @return list<string>
     */
    private function fileNames(string $table, int $uid, string $field): array
    {
        if ('' === $field) {
            return [];
        }

        return array_values(array_filter(array_map(
            $this->fileName(...),
            $this->recordRepository->findReferencedFileUids($table, $uid, $field),
        )));
    }

    private function fileName(int $fileUid): string
    {
        try {
            return $this->recordAccess->assertFileReadAccess($fileUid)->getName();
        } catch (\Throwable) {
            return $this->label('media.unknownFile', [$fileUid]);
        }
    }

    private function languageTitle(int $pageId, string $code): string
    {
        if ($pageId > 0 && '' !== $code) {
            try {
                foreach ($this->siteFinder->getSiteByPageId($pageId)->getAllLanguages() as $language) {
                    if (strtolower($language->getLocale()->getLanguageCode()) === strtolower($code)) {
                        return $language->getTitle();
                    }
                }
            } catch (\Throwable) {
                return $code;
            }
        }

        return $code;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function intArgument(array $arguments, string $key): int
    {
        return is_numeric($arguments[$key] ?? null) ? (int) $arguments[$key] : 0;
    }

    /**
     * @param list<int|string> $arguments
     */
    private function label(string $key, array $arguments = []): string
    {
        return $this->localizationService->translate(self::LLL.$key, $arguments);
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return array<string, mixed>
     */
    private function wrap(array $records, int $total): array
    {
        return [
            'kind' => 'records',
            'records' => $records,
            'total' => $total,
            'truncated' => $total > count($records),
        ];
    }
}
