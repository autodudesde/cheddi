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

use AutoDudes\AiSuiteMcp\Mcp\Service\McpExcludedTablesService;

class OpenItemRuleTables
{
    public const STANDARD_DOKTYPE = 1;
    private const PASSTHROUGH_TYPES = ['passthrough', 'none', 'user', 'flex', 'imageManipulation'];
    private const TECHNICAL_CTRL_KEYS = ['languageField', 'transOrigPointerField', 'transOrigDiffSourceField', 'translationSource', 'type', 'sortby', 'tstamp', 'crdate', 'delete', 'origUid', 'editlock'];
    private const TECHNICAL_FIELDS = ['pages' => ['cache_tags', 'TSconfig', 'target'], 'tt_content' => ['target']];
    private const CONFIGURATION_TABLE_PREFIXES = ['tx_aisuite_', 'tx_cheddi_', 'tx_freddi_'];

    public function __construct(
        private readonly McpExcludedTablesService $excludedTables,
    ) {}

    /**
     * @return list<string>
     */
    public function selectable(): array
    {
        $tables = [];
        foreach (array_keys($GLOBALS['TCA'] ?? []) as $table) {
            if ($this->isAllowed((string) $table)) {
                $tables[] = (string) $table;
            }
        }
        sort($tables);

        return $tables;
    }

    public function isConfiguration(string $table): bool
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        if (true === ($ctrl['hideTable'] ?? false) || true === ($ctrl['adminOnly'] ?? false)) {
            return true;
        }
        foreach (self::CONFIGURATION_TABLE_PREFIXES as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    public function technicalFields(string $table): array
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        $names = array_map(static fn (string $key): string => is_string($ctrl[$key] ?? null) ? $ctrl[$key] : '', self::TECHNICAL_CTRL_KEYS);
        $enableColumns = is_array($ctrl['enablecolumns'] ?? null) ? array_values(array_filter($ctrl['enablecolumns'], 'is_string')) : [];

        return array_fill_keys(array_filter([...$names, ...$enableColumns, ...(self::TECHNICAL_FIELDS[$table] ?? [])]), true);
    }

    public function isRichText(string $table, string $field): bool
    {
        $tca = $GLOBALS['TCA'][$table] ?? [];
        if (true === ($tca['columns'][$field]['config']['enableRichtext'] ?? false)) {
            return true;
        }
        foreach ($tca['types'] ?? [] as $type) {
            if (true === ($type['columnsOverrides'][$field]['config']['enableRichtext'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return null|array<string, true>
     */
    public function fieldsShownInEveryType(string $table): ?array
    {
        $tca = $GLOBALS['TCA'][$table] ?? [];
        $types = $tca['types'] ?? [];
        if ('pages' === $table && isset($types[self::STANDARD_DOKTYPE])) {
            $types = [self::STANDARD_DOKTYPE => $types[self::STANDARD_DOKTYPE]];
        }
        if (!isset($tca['ctrl']['type']) || ('pages' !== $table && count($types) < 2)) {
            return null;
        }

        $common = null;
        foreach ($types as $type) {
            $fields = [];
            foreach (explode(',', (string) ($type['showitem'] ?? '')) as $token) {
                $parts = array_map('trim', explode(';', $token));
                if ('--palette--' === $parts[0]) {
                    foreach (explode(',', (string) ($tca['palettes'][$parts[2] ?? '']['showitem'] ?? '')) as $paletteToken) {
                        $fields[trim(explode(';', $paletteToken)[0])] = true;
                    }

                    continue;
                }
                $fields[$parts[0]] = true;
            }
            $common = null === $common ? $fields : array_intersect_key($common, $fields);
        }

        return $common ?? [];
    }

    public function isAllowed(string $table): bool
    {
        if ('' === $table || !isset($GLOBALS['TCA'][$table]) || str_starts_with($table, 'tx_cheddi_')) {
            return false;
        }
        if (1 === (int) ($GLOBALS['TCA'][$table]['ctrl']['rootLevel'] ?? 0) && 'pages' !== $table) {
            return false;
        }

        return !$this->excludedTables->isExcluded($table);
    }

    /**
     * @return list<string>
     */
    public function fieldsOf(string $table): array
    {
        if (!$this->isAllowed($table)) {
            return [];
        }

        $fields = [];
        foreach ($GLOBALS['TCA'][$table]['columns'] ?? [] as $field => $column) {
            if (!in_array($column['config']['type'] ?? '', self::PASSTHROUGH_TYPES, true)) {
                $fields[] = (string) $field;
            }
        }
        sort($fields);

        return $fields;
    }
}
