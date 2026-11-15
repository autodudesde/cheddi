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

use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\AiSuiteMcp\Mcp\Service\TcaLabelService;
use AutoDudes\Cheddi\Enumeration\OpenItemRuleComparisonEnumeration as Comparison;
use AutoDudes\Cheddi\Service\Chat\OpenItem\RuleSource\OpenItemRuleSources;

class OpenItemRuleSummary
{
    private const LABEL_PREFIX = 'LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.openItemRule.';
    private const ITEM_TYPES = ['select', 'radio'];

    public function __construct(
        private readonly OpenItemRuleSources $ruleSources,
        private readonly TcaLabelService $tcaLabels,
        private readonly LocalizationService $localizationService,
    ) {}

    /**
     * @param array<string, mixed> $rule
     */
    public function summary(array $rule): string
    {
        $source = '' === (string) ($rule['source'] ?? '') ? null : $this->ruleSources->get((string) $rule['source']);
        if (null !== $source) {
            return $source->summary(OpenItemRuleSources::parametersOf($rule));
        }

        $table = (string) $rule['source_table'];
        $parts = array_map(fn (array $condition): string => $this->conditionSummary($table, $condition), $rule['conditions'] ?? []);
        $joiner = $this->localizationService->translate(self::LABEL_PREFIX.'summary.join.'.('any' === ($rule['match_mode'] ?? 'all') ? 'any' : 'all'));

        return sprintf('%s: %s', $this->tcaLabels->getTableLabel($table), implode(' '.$joiner.' ', $parts));
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function conditionSummary(string $table, array $condition): string
    {
        $comparison = (string) ($condition['comparison'] ?? '');
        if (!in_array($comparison, Comparison::ALL, true)) {
            return '';
        }

        $subject = Comparison::HAS_NO_RELATED === $comparison
            ? $this->tcaLabels->getTableLabel((string) ($condition['related_table'] ?? ''))
            : $this->tcaLabels->getFieldLabel($table, (string) ($condition['field_name'] ?? ''));

        return $this->localizationService->translate(self::LABEL_PREFIX.'summary.'.$comparison, [$subject, $this->valueLabel($table, (string) ($condition['field_name'] ?? ''), (string) ($condition['compare_value'] ?? ''))]);
    }

    private function valueLabel(string $table, string $field, string $value): string
    {
        $type = (string) ($GLOBALS['TCA'][$table]['columns'][$field]['config']['type'] ?? '');

        return in_array($type, self::ITEM_TYPES, true) && '' !== $value ? $this->tcaLabels->getTypeItemLabel($table, $field, $value) : $value;
    }
}
