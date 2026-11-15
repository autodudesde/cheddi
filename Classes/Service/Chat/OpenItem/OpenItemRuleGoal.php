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

use AutoDudes\Cheddi\Enumeration\OpenItemAreaEnumeration;
use AutoDudes\Cheddi\Enumeration\OpenItemRuleComparisonEnumeration as Comparison;

class OpenItemRuleGoal
{
    public const MISSING = 'missing';
    public const STALE = 'stale';
    public const DATE = 'date';
    public const WORD = 'word';

    public const DATE_PASSED = 'passed';
    public const DATE_WITHIN = 'within';

    public const REQUIRED_COMPARISON = [
        self::MISSING => Comparison::IS_EMPTY,
        self::DATE => Comparison::DATE_PASSED,
        self::WORD => Comparison::CONTAINS,
    ];

    public const FIELD_TYPES = [
        self::MISSING => ['input', 'text', 'email', 'link', 'file', 'category'],
        self::DATE => ['datetime'],
        self::WORD => ['input', 'text'],
    ];

    private const MAX_DAYS = 3650;
    private const MAX_WORD_LENGTH = 100;

    /**
     * @param array<string, mixed> $input
     *
     * @return null|array<string, mixed>
     */
    public function definition(array $input): ?array
    {
        $table = (string) ($input['table'] ?? '');
        $field = (string) ($input['field'] ?? '');
        $goal = (string) ($input['goal'] ?? '');
        if ('' === $table || (self::STALE !== $goal && '' === $field)) {
            return null;
        }

        $condition = $this->conditionOf($goal, $table, $field, $input);
        if (null === $condition) {
            return null;
        }

        return [
            'title' => (string) ($input['title'] ?? ''),
            'source' => '',
            'area' => 'pages' === $table && in_array($field, OpenItemRuleSuggestions::SEO_PAGE_FIELDS, true) ? OpenItemAreaEnumeration::SEO : OpenItemAreaEnumeration::CONTENT,
            'source_table' => $table,
            'match_mode' => 'all',
            'preset' => '',
            'root_page' => max(0, (int) ($input['root_page'] ?? 0)),
            'severity' => 'notice',
            'conditions' => [
                ...('pages' === $table ? [['field_name' => 'doktype', 'comparison' => Comparison::EQUALS, 'compare_value' => (string) OpenItemRuleTables::STANDARD_DOKTYPE]] : []),
                $condition,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return null|array{field_name: string, comparison: string, compare_value: string}
     */
    private function conditionOf(string $goal, string $table, string $field, array $input): ?array
    {
        $days = (int) ($input['days'] ?? 0);
        $validDays = $days > 0 && $days <= self::MAX_DAYS;
        $value = trim((string) ($input['value'] ?? ''));

        return match (true) {
            self::MISSING === $goal => ['field_name' => $field, 'comparison' => 'sys_file_reference' === $table && 'alternative' === $field ? Comparison::EFFECTIVE_EMPTY : Comparison::IS_EMPTY, 'compare_value' => ''],
            self::STALE === $goal && $validDays => ['field_name' => '', 'comparison' => Comparison::OLDER_THAN_DAYS, 'compare_value' => (string) $days],
            self::DATE === $goal && self::DATE_PASSED === ($input['dateMode'] ?? '') => ['field_name' => $field, 'comparison' => Comparison::DATE_PASSED, 'compare_value' => ''],
            self::DATE === $goal && self::DATE_WITHIN === ($input['dateMode'] ?? '') && $validDays => ['field_name' => $field, 'comparison' => Comparison::DATE_WITHIN_DAYS, 'compare_value' => (string) $days],
            self::WORD === $goal && '' !== $value && mb_strlen($value) <= self::MAX_WORD_LENGTH => ['field_name' => $field, 'comparison' => Comparison::CONTAINS, 'compare_value' => $value],
            default => null,
        };
    }
}
