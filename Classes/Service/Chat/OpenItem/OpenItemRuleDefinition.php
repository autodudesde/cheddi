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

final class OpenItemRuleDefinition
{
    public const SEVERITIES = ['notice', 'warning', 'error'];
    public const MAX_TEXT_LENGTH = 255;

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $severity = (string) ($input['severity'] ?? '');
        $conditions = is_array($input['conditions'] ?? null) ? $input['conditions'] : [];

        return [
            'title' => mb_substr(trim((string) ($input['title'] ?? '')), 0, self::MAX_TEXT_LENGTH),
            'source' => (string) ($input['source'] ?? ''),
            'area' => OpenItemAreaEnumeration::normalize((string) ($input['area'] ?? '')),
            'source_table' => (string) ($input['source_table'] ?? ''),
            'match_mode' => 'any' === ($input['match_mode'] ?? '') ? 'any' : 'all',
            'preset' => (string) ($input['preset'] ?? ''),
            'conditions' => array_map(static fn (mixed $condition): array => self::normalizeCondition($condition), array_values($conditions)),
            'root_page' => max(0, (int) ($input['root_page'] ?? 0)),
            'severity' => in_array($severity, self::SEVERITIES, true) ? $severity : 'notice',
        ];
    }

    /**
     * @param array<string, mixed> $rule
     */
    public static function signature(array $rule): string
    {
        $conditions = array_map(
            static fn (array $condition): string => $condition['field_name'].'|'.$condition['comparison'].'|'.$condition['compare_value'],
            self::normalize($rule)['conditions'],
        );
        sort($conditions);

        return (string) ($rule['source_table'] ?? '').'#'.implode('#', $conditions);
    }

    /**
     * @return array{field_name: string, comparison: string, compare_value: string, related_table: string, related_field: string}
     */
    private static function normalizeCondition(mixed $condition): array
    {
        $condition = is_array($condition) ? $condition : [];
        $comparison = (string) ($condition['comparison'] ?? '');

        return [
            'field_name' => (string) ($condition['field_name'] ?? ''),
            'comparison' => in_array($comparison, Comparison::ALL, true) ? $comparison : '',
            'compare_value' => mb_substr((string) ($condition['compare_value'] ?? ''), 0, self::MAX_TEXT_LENGTH),
            'related_table' => (string) ($condition['related_table'] ?? ''),
            'related_field' => (string) ($condition['related_field'] ?? ''),
        ];
    }
}
