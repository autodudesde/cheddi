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

use AutoDudes\Cheddi\Domain\Repository\OpenItemRuleRepository;
use AutoDudes\Cheddi\Enumeration\OpenItemRuleComparisonEnumeration as Comparison;
use AutoDudes\Cheddi\Service\Chat\OpenItem\CustomRulesSource;
use AutoDudes\Cheddi\Service\Chat\OpenItem\OpenItemRuleTables;
use TYPO3\CMS\Backend\Utility\BackendUtility;

class OpenItemFixTargets
{
    public const IMAGE_TABLES = ['sys_file_reference' => 'uid_local', 'sys_file_metadata' => 'file'];
    private const EMPTY_COMPARISONS = [Comparison::IS_EMPTY, Comparison::EFFECTIVE_EMPTY];
    private const TEXT_TYPES = ['input', 'text'];
    private const IMAGE_FIELDS = ['alternative', 'title', 'description'];

    /**
     * @var array<int, null|array<string, mixed>>
     */
    private array $rules = [];

    public function __construct(
        private readonly OpenItemRuleRepository $ruleRepository,
        private readonly OpenItemRuleTables $ruleTables,
    ) {}

    /**
     * @param array{kind: string, item_key: string, page_uid: int, record_table: string, record_uid: int} $row
     */
    public function kindOf(array $row): ?string
    {
        return $this->targetOf($row, false)?->kind;
    }

    /**
     * @param array{kind: string, item_key: string, page_uid: int, record_table: string, record_uid: int} $row
     */
    public function targetOf(array $row, bool $withFile = true): ?OpenItemFixTarget
    {
        if (CustomRulesSource::KIND !== $row['kind'] || '' === $row['record_table'] || $row['record_uid'] <= 0) {
            return null;
        }
        $rule = $this->ruleOf($row['item_key']);
        if (null === $rule || (string) $rule['source_table'] !== $row['record_table']) {
            return null;
        }

        $fix = $this->fixOfRule($rule);
        if (null === $fix) {
            return null;
        }
        $table = $row['record_table'];
        if (OpenItemFixTarget::KIND_TEXT === $fix['kind']) {
            return new OpenItemFixTarget($table, $row['record_uid'], $fix['field'], OpenItemFixTarget::KIND_TEXT, $row['page_uid']);
        }
        if (!$withFile) {
            return new OpenItemFixTarget($table, $row['record_uid'], $fix['field'], OpenItemFixTarget::KIND_IMAGE, $row['page_uid']);
        }
        $record = BackendUtility::getRecord($table, $row['record_uid'], self::IMAGE_TABLES[$table]);
        $fileUid = (int) ($record[self::IMAGE_TABLES[$table]] ?? 0);

        return $fileUid > 0 ? new OpenItemFixTarget($table, $row['record_uid'], $fix['field'], OpenItemFixTarget::KIND_IMAGE, $row['page_uid'], $fileUid) : null;
    }

    /**
     * @param array<string, mixed> $rule
     *
     * @return null|array{field: string, kind: string}
     */
    public function fixOfRule(array $rule): ?array
    {
        if ('' !== (string) ($rule['source'] ?? '')) {
            return null;
        }
        $table = (string) ($rule['source_table'] ?? '');
        foreach ($rule['conditions'] ?? [] as $condition) {
            $field = (string) ($condition['field_name'] ?? '');
            if (!in_array((string) ($condition['comparison'] ?? ''), self::EMPTY_COMPARISONS, true) || !$this->isPlainTextField($table, $field)) {
                continue;
            }
            if (!isset(self::IMAGE_TABLES[$table])) {
                return ['field' => $field, 'kind' => OpenItemFixTarget::KIND_TEXT];
            }

            return in_array($field, self::IMAGE_FIELDS, true) ? ['field' => $field, 'kind' => OpenItemFixTarget::KIND_IMAGE] : null;
        }

        return null;
    }

    private function isPlainTextField(string $table, string $field): bool
    {
        $config = $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? [];

        return in_array($config['type'] ?? '', self::TEXT_TYPES, true) && !$this->ruleTables->isRichText($table, $field);
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
