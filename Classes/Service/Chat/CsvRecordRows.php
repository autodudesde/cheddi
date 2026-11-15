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

use AutoDudes\AiSuite\Service\WorkspaceContextService;
use AutoDudes\AiSuiteMcp\Mcp\Service\RecordAccessService;
use AutoDudes\AiSuiteMcp\Mcp\Service\RecordSelectionService;
use AutoDudes\AiSuiteMcp\Mcp\Service\WorkspaceRecordService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

class CsvRecordRows
{
    private const IDENTITY_FIELDS = ['uid', 'pid'];

    public function __construct(
        private readonly RecordSelectionService $recordSelection,
        private readonly RecordAccessService $recordAccess,
        private readonly WorkspaceRecordService $workspaceRecords,
        private readonly WorkspaceContextService $workspaceContextService,
        private readonly ChatOrientationService $orientationService,
        private readonly ChatSettingsService $chatSettings,
    ) {}

    /**
     * @param array<mixed> $query
     *
     * @return list<list<string>>|string
     */
    public function rows(array $query, int $width, int $maxRows, ?int $sessionWorkspace = null): array|string
    {
        if (null === $sessionWorkspace) {
            return $this->read($query, $width, $maxRows);
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return 'No backend user.';
        }
        $this->chatSettings->applyToMcpSurface();
        $workspaceId = $this->orientationService->resolveWorkspaceForDisplay($sessionWorkspace)['id'];

        return $this->workspaceContextService->withWorkspace(
            $backendUser,
            $workspaceId,
            fn (): array|string => $this->read($query, $width, $maxRows),
        );
    }

    /**
     * @param array<mixed> $query
     *
     * @return list<list<string>>|string
     */
    private function read(array $query, int $width, int $maxRows): array|string
    {
        $table = $query['table'] ?? null;
        if (!\is_string($table) || '' === $table) {
            return '`records.table` must name the table to export.';
        }

        $fields = $query['fields'] ?? null;
        if (!\is_array($fields) || [] === $fields || !array_is_list($fields)) {
            return '`records.fields` must list the field of each column, in column order.';
        }
        if (\count($fields) !== $width) {
            return sprintf('`records.fields` names %d fields for %d columns; give one field per column.', \count($fields), $width);
        }
        foreach ($fields as $field) {
            if (!\is_string($field) || (!\in_array($field, self::IDENTITY_FIELDS, true) && !$this->recordAccess->canAccessField($table, $field))) {
                return sprintf('`%s` is not a field of `%s` you can read. readRecordSchema lists the fields.', \is_scalar($field) ? (string) $field : '?', $table);
            }
        }

        $filters = \is_array($query['filters'] ?? null) ? $query['filters'] : [];
        $selection = $this->recordSelection->select(
            $table,
            isset($query['pid']) && is_numeric($query['pid']) ? (int) $query['pid'] : null,
            isset($query['rootPageId']) && is_numeric($query['rootPageId']) ? (int) $query['rootPageId'] : null,
            $filters,
            $maxRows + 1,
            0,
            false,
        );
        if (\is_string($selection)) {
            return $selection;
        }
        if (\count($selection['uids']) > $maxRows) {
            return sprintf('More than %d records match; narrow `records` with `filters` or a smaller `rootPageId`.', $maxRows);
        }

        $rows = [];
        foreach ($this->workspaceRecords->overlay($table, $selection['uids']) as $record) {
            $uid = (int) ($record['uid'] ?? 0);
            $cells = [];
            foreach ($fields as $field) {
                $cells[] = $this->cell($table, $field, $record, $uid);
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function cell(string $table, string $field, array $record, int $uid): string
    {
        $value = $record[$field] ?? null;
        if (null === $value || '' === $value) {
            return '';
        }
        if (\in_array($field, self::IDENTITY_FIELDS, true)) {
            return (string) $value;
        }

        $processed = BackendUtility::getProcessedValue($table, $field, $value, 0, true, true, $uid, true, (int) ($record['pid'] ?? 0), $record);

        return trim((string) ($processed ?? (\is_scalar($value) ? $value : '')));
    }
}
