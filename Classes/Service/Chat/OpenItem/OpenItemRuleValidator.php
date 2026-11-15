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
use AutoDudes\Cheddi\Enumeration\OpenItemRuleComparisonEnumeration as Comparison;
use AutoDudes\Cheddi\Enumeration\OpenItemRuleProblemEnumeration;
use AutoDudes\Cheddi\Service\Chat\OpenItem\RuleSource\OpenItemRuleSources;

class OpenItemRuleValidator
{
    private const LABEL_PREFIX = 'LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.openItemRule.problem.';

    public function __construct(
        private readonly OpenItemRuleTables $ruleTables,
        private readonly OpenItemRuleSources $ruleSources,
        private readonly LocalizationService $localizationService,
    ) {}

    /**
     * @param array<string, mixed> $rule
     */
    public function problemOf(array $rule): ?string
    {
        return $this->conditionProblemOf($rule);
    }

    /**
     * @param array<string, mixed> $rule
     */
    public function conditionProblemOf(array $rule): ?string
    {
        $sourceIdentifier = (string) ($rule['source'] ?? '');
        if ('' !== $sourceIdentifier) {
            $source = $this->ruleSources->get($sourceIdentifier);

            return null === $source ? OpenItemRuleProblemEnumeration::SOURCE_UNAVAILABLE : $source->problemOf(OpenItemRuleSources::parametersOf($rule));
        }

        $table = (string) ($rule['source_table'] ?? '');
        if (!$this->ruleTables->isAllowed($table)) {
            return OpenItemRuleProblemEnumeration::TABLE_BLOCKED;
        }

        $conditions = $rule['conditions'] ?? [];
        if ([] === $conditions) {
            return OpenItemRuleProblemEnumeration::CONDITION_MISSING;
        }

        foreach ($conditions as $condition) {
            $problem = $this->problemOfCondition($table, $condition);
            if (null !== $problem) {
                return $problem;
            }
        }

        return null;
    }

    public function problemLabel(string $problem): string
    {
        $known = in_array($problem, OpenItemRuleProblemEnumeration::ALL, true) ? $problem : OpenItemRuleProblemEnumeration::FAILED;

        return $this->localizationService->translate(self::LABEL_PREFIX.$known);
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function problemOfCondition(string $table, array $condition): ?string
    {
        $comparison = (string) ($condition['comparison'] ?? '');
        if (!in_array($comparison, Comparison::ALL, true)) {
            return OpenItemRuleProblemEnumeration::CONDITION_MISSING;
        }

        $value = trim((string) ($condition['compare_value'] ?? ''));
        if (in_array($comparison, Comparison::WITH_DAYS, true) && (int) $value < 1) {
            return OpenItemRuleProblemEnumeration::VALUE_MISSING;
        }
        if (Comparison::OLDER_THAN_DAYS === $comparison) {
            return isset($GLOBALS['TCA'][$table]['ctrl']['tstamp']) ? null : OpenItemRuleProblemEnumeration::NO_CHANGE_DATE;
        }
        if (Comparison::HAS_NO_RELATED === $comparison) {
            $relatedTable = (string) ($condition['related_table'] ?? '');
            $relatedField = (string) ($condition['related_field'] ?? '');
            if (!$this->ruleTables->isAllowed($relatedTable)) {
                return OpenItemRuleProblemEnumeration::TABLE_BLOCKED;
            }

            return 'pid' === $relatedField || in_array($relatedField, $this->ruleTables->fieldsOf($relatedTable), true)
                ? null
                : OpenItemRuleProblemEnumeration::FIELD_BLOCKED;
        }

        if (!in_array((string) ($condition['field_name'] ?? ''), $this->ruleTables->fieldsOf($table), true)) {
            return OpenItemRuleProblemEnumeration::FIELD_BLOCKED;
        }

        return in_array($comparison, Comparison::WITH_VALUE, true) && '' === $value
            ? OpenItemRuleProblemEnumeration::VALUE_MISSING
            : null;
    }
}
