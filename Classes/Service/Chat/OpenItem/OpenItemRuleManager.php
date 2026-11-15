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
use AutoDudes\AiSuiteMcp\Mcp\Service\TcaLabelService;
use AutoDudes\Cheddi\Domain\Repository\OpenItemRepository;
use AutoDudes\Cheddi\Domain\Repository\OpenItemRuleRepository;
use AutoDudes\Cheddi\Domain\Repository\OpenItemSourceRepository;
use AutoDudes\Cheddi\Enumeration\OpenItemAreaEnumeration;
use AutoDudes\Cheddi\Enumeration\OpenItemRuleComparisonEnumeration as Comparison;
use AutoDudes\Cheddi\Service\Chat\OpenItemCollector;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

class OpenItemRuleManager
{
    private const LABEL_PREFIX = 'LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.openItemRule.';
    private const AREA_LABEL_PREFIX = 'LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.openItems.area.';

    private const COMPARISONS_BY_TYPE = [
        'datetime' => [Comparison::IS_EMPTY, Comparison::NOT_EMPTY, Comparison::DATE_PASSED, Comparison::DATE_WITHIN_DAYS],
        'file' => [Comparison::IS_EMPTY, Comparison::NOT_EMPTY],
        'inline' => [Comparison::IS_EMPTY, Comparison::NOT_EMPTY],
        'group' => [Comparison::IS_EMPTY, Comparison::NOT_EMPTY],
        'category' => [Comparison::IS_EMPTY, Comparison::NOT_EMPTY],
        'number' => [Comparison::IS_EMPTY, Comparison::NOT_EMPTY, Comparison::EQUALS, Comparison::NOT_EQUALS, Comparison::GREATER, Comparison::LESS],
        'check' => [Comparison::EQUALS, Comparison::NOT_EQUALS],
        'select' => [Comparison::IS_EMPTY, Comparison::NOT_EMPTY, Comparison::EQUALS, Comparison::NOT_EQUALS],
        'radio' => [Comparison::IS_EMPTY, Comparison::NOT_EMPTY, Comparison::EQUALS, Comparison::NOT_EQUALS],
    ];

    private const TEXT_COMPARISONS = [Comparison::IS_EMPTY, Comparison::NOT_EMPTY, Comparison::CONTAINS, Comparison::NOT_CONTAINS, Comparison::EQUALS, Comparison::NOT_EQUALS];

    private const MAX_TEXT_LENGTH = 255;
    private const GOAL_LABEL_PREFIX = 'LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.openItemRule.goal.';

    public function __construct(
        private readonly OpenItemRuleRepository $rules,
        private readonly OpenItemRepository $items,
        private readonly OpenItemRuleAccess $access,
        private readonly OpenItemRuleValidator $validator,
        private readonly OpenItemCatalog $catalog,
        private readonly OpenItemPresetCatalog $presetCatalog,
        private readonly OpenItemCollector $collector,
        private readonly OpenItemRuleSuggestions $suggestions,
        private readonly OpenItemRulePreview $preview,
        private readonly OpenItemRuleGoal $goals,
        private readonly OpenItemRuleTables $ruleTables,
        private readonly OpenItemRuleSummary $ruleSummary,
        private readonly OpenItemSourceRepository $sources,
        private readonly PagesRepository $pages,
        private readonly TcaLabelService $tcaLabels,
        private readonly LocalizationService $localizationService,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listFor(BackendUserAuthentication $backendUser): array
    {
        $this->catalog->sync(time());

        return array_map(
            fn (array $rule): array => $this->present($backendUser, $rule),
            $this->rules->findAllNotDeleted(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function optionsFor(BackendUserAuthentication $backendUser, int $currentPage): array
    {
        $tables = array_map(fn (string $table): array => [
            'value' => $table,
            'label' => $this->tcaLabels->getTableLabel($table),
            'supportsAge' => isset($GLOBALS['TCA'][$table]['ctrl']['tstamp']),
        ], $this->access->tablesFor($backendUser));
        usort($tables, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return [
            'tables' => $tables,
            'scopes' => $this->scopesFor($backendUser, $currentPage),
        ];
    }

    /**
     * @return array{tables: list<array{value: string, label: string, records: int}>, scopes: list<array{value: int, label: string}>}
     */
    public function goalOptionsFor(BackendUserAuthentication $backendUser, int $currentPage): array
    {
        $tables = [];
        foreach ($this->access->tablesFor($backendUser) as $table) {
            if ($this->ruleTables->isConfiguration($table)) {
                continue;
            }
            $records = array_sum(array_column($this->sources->fillRates($table, []), 'total'));
            if ($records > 0) {
                $tables[] = ['value' => $table, 'label' => $this->tcaLabels->getTableLabel($table), 'records' => $records];
            }
        }
        usort($tables, static fn (array $a, array $b): int => $b['records'] <=> $a['records']);

        return ['tables' => $tables, 'scopes' => $this->scopesFor($backendUser, $currentPage)];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function goalFieldsFor(BackendUserAuthentication $backendUser, string $goal, string $table): array
    {
        $required = OpenItemRuleGoal::REQUIRED_COMPARISON[$goal] ?? null;
        if (null === $required || $this->ruleTables->isConfiguration($table)) {
            return [];
        }
        $technical = $this->ruleTables->technicalFields($table);
        $shown = 'pages' === $table ? $this->ruleTables->fieldsShownInEveryType($table) : null;
        $fields = [];
        foreach ($this->fieldsFor($backendUser, $table) as $field) {
            $type = (string) ($GLOBALS['TCA'][$table]['columns'][$field['value']]['config']['type'] ?? '');
            if (in_array($required, $field['comparisons'], true)
                && in_array($type, OpenItemRuleGoal::FIELD_TYPES[$goal] ?? [], true)
                && !isset($technical[$field['value']])
                && !str_starts_with($field['value'], 't3ver_')
                && (null === $shown || isset($shown[$field['value']]))) {
                $fields[] = ['value' => $field['value'], 'label' => $field['label']];
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return null|array<string, mixed>
     */
    public function goalDefinition(BackendUserAuthentication $backendUser, array $input): ?array
    {
        $goal = (string) ($input['goal'] ?? '');
        $table = (string) ($input['table'] ?? '');
        if ($this->ruleTables->isConfiguration($table) || !in_array($table, $this->access->tablesFor($backendUser), true)) {
            return null;
        }
        if (OpenItemRuleGoal::STALE !== $goal && !in_array((string) ($input['field'] ?? ''), array_column($this->goalFieldsFor($backendUser, $goal, $table), 'value'), true)) {
            return null;
        }
        if (OpenItemRuleGoal::STALE === $goal && !isset($GLOBALS['TCA'][$table]['ctrl']['tstamp'])) {
            return null;
        }

        $definition = $this->goals->definition($input);
        if (null === $definition || !$this->access->mayEdit($backendUser, $definition)) {
            return null;
        }
        $definition['title'] = mb_substr(trim((string) $definition['title']), 0, self::MAX_TEXT_LENGTH);

        return $definition;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return null|array<string, mixed>
     */
    public function previewGoal(BackendUserAuthentication $backendUser, array $input, int $now): ?array
    {
        $definition = $this->goalDefinition($backendUser, $input);
        $preview = null === $definition ? null : $this->preview->preview($backendUser, $definition, $now);

        return null === $preview ? null : $preview + ['defaultTitle' => $this->goalTitle($input)];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return null|array{rule?: array<string, mixed>, problem?: string}
     */
    public function createFromGoal(BackendUserAuthentication $backendUser, array $input, int $now): ?array
    {
        $definition = $this->goalDefinition($backendUser, $input);
        if (null === $definition) {
            return null;
        }
        if ('' === $definition['title']) {
            $definition['title'] = mb_substr($this->goalTitle($input), 0, self::MAX_TEXT_LENGTH);
        }

        return $this->save($backendUser, 0, $definition, $now);
    }

    /**
     * @return list<array{value: string, label: string, comparisons: list<string>}>
     */
    public function fieldsFor(BackendUserAuthentication $backendUser, string $table): array
    {
        $fields = array_map(fn (string $field): array => [
            'value' => $field,
            'label' => $this->tcaLabels->getFieldLabel($table, $field),
            'comparisons' => self::COMPARISONS_BY_TYPE[$GLOBALS['TCA'][$table]['columns'][$field]['config']['type'] ?? ''] ?? self::TEXT_COMPARISONS,
        ], $this->access->fieldsFor($backendUser, $table));
        usort($fields, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $fields;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function normalize(array $input): array
    {
        return OpenItemRuleDefinition::normalize($input);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return null|array{rule?: array<string, mixed>, problem?: string}
     */
    public function save(BackendUserAuthentication $backendUser, int $uid, array $input, int $now): ?array
    {
        $values = $this->normalize($input);
        if (!$this->access->mayEdit($backendUser, $values) || ($uid > 0 && null === $this->editableRule($backendUser, $uid))) {
            return null;
        }

        if ('' === $values['title']) {
            $values['title'] = mb_substr($this->ruleSummary->summary($values), 0, self::MAX_TEXT_LENGTH);
        }

        $problem = $this->validator->problemOf($values);
        if (null !== $problem) {
            return ['problem' => $this->validator->problemLabel($problem)];
        }

        if ($uid > 0) {
            $this->rules->updateRule($uid, $values + ['customized' => (int) ('' !== $values['preset'])], $now);
        } else {
            $uid = $this->rules->insertRule($values, $now);
        }
        $this->collector->refreshRule($uid, $now);

        return ['rule' => $this->present($backendUser, (array) $this->rules->findRule($uid))];
    }

    /**
     * @param list<string> $ids
     */
    public function adopt(BackendUserAuthentication $backendUser, array $ids, int $now): int
    {
        $presets = [];
        foreach ([...$this->rules->findDeletedPresets(), ...$this->rules->findAllNotDeleted()] as $rule) {
            if ('' !== (string) $rule['preset']) {
                $presets[(string) $rule['preset']] = $rule;
            }
        }

        $adopted = 0;
        foreach (array_unique($ids) as $id) {
            if (str_starts_with($id, OpenItemRuleSuggestions::PRESET_PREFIX)) {
                $rule = $presets[substr($id, strlen(OpenItemRuleSuggestions::PRESET_PREFIX))] ?? null;
                if (null !== $rule && 0 !== (int) $rule['deleted']) {
                    if (0 !== (int) ($rule['inapplicable'] ?? 0) || !$this->access->mayEdit($backendUser, $rule)) {
                        continue;
                    }
                    $this->rules->restoreHidden((int) $rule['uid'], $now);
                    $rule['hidden'] = 1;
                }
                if (null !== $rule && 1 === (int) $rule['hidden'] && null !== $this->setActive($backendUser, (int) $rule['uid'], true, $now)) {
                    ++$adopted;
                }

                continue;
            }
            $values = str_starts_with($id, OpenItemRuleSuggestions::PROPOSAL_PREFIX) ? $this->suggestions->proposal($backendUser, $id) : null;
            if (null === $values) {
                continue;
            }
            foreach ($this->wholeSiteScopes($backendUser) as $rootPage) {
                $uid = (int) ($this->save($backendUser, 0, $values + ['root_page' => $rootPage], $now)['rule']['uid'] ?? 0);
                if ($uid > 0) {
                    $this->rules->markSuggested($uid);
                    ++$adopted;
                }
            }
        }

        return $adopted;
    }

    /**
     * @return null|array<string, mixed>
     */
    public function setActive(BackendUserAuthentication $backendUser, int $uid, bool $active, int $now): ?array
    {
        $rule = $this->editableRule($backendUser, $uid);
        if (null === $rule || ($active && 0 !== (int) ($rule['inapplicable'] ?? 0))) {
            return null;
        }

        $this->rules->setHidden($uid, !$active, $now);
        $this->collector->refreshRule($uid, $now);

        return $this->present($backendUser, (array) $this->rules->findRule($uid));
    }

    public function delete(BackendUserAuthentication $backendUser, int $uid, int $now): bool
    {
        if (null === $this->editableRule($backendUser, $uid)) {
            return false;
        }

        $this->rules->markDeleted($uid, $now);
        $this->collector->refreshRule($uid, $now);

        return true;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function goalTitle(array $input): string
    {
        $table = (string) ($input['table'] ?? '');
        $field = (string) ($input['field'] ?? '');
        $tableLabel = $this->tcaLabels->getTableLabel($table);
        $fieldLabel = '' === $field ? '' : rtrim($this->tcaLabels->getFieldLabel($table, $field), ': ');
        $days = (int) ($input['days'] ?? 0);

        return match ((string) ($input['goal'] ?? '')) {
            OpenItemRuleGoal::STALE => $this->localizationService->translate(self::GOAL_LABEL_PREFIX.'titleStale', [(int) round($days / 30), $tableLabel]),
            OpenItemRuleGoal::DATE => OpenItemRuleGoal::DATE_PASSED === ($input['dateMode'] ?? '')
                ? $this->localizationService->translate(self::GOAL_LABEL_PREFIX.'titleDatePassed', [$fieldLabel, $tableLabel])
                : $this->localizationService->translate(self::GOAL_LABEL_PREFIX.'titleDateWithin', [$fieldLabel, $days, $tableLabel]),
            OpenItemRuleGoal::WORD => $this->localizationService->translate(self::GOAL_LABEL_PREFIX.'titleWord', [$fieldLabel, trim((string) ($input['value'] ?? '')), $tableLabel]),
            default => $this->localizationService->translate(self::GOAL_LABEL_PREFIX.'titleMissing', [$fieldLabel, $tableLabel]),
        };
    }

    /**
     * @return list<int>
     */
    private function wholeSiteScopes(BackendUserAuthentication $backendUser): array
    {
        if ($backendUser->isAdmin()) {
            return [0];
        }

        return array_values(array_filter(
            array_map('intval', $backendUser->getWebmounts()),
            fn (int $mount): bool => $this->access->mayUseScope($backendUser, $mount),
        ));
    }

    /**
     * @return null|array<string, mixed>
     */
    private function editableRule(BackendUserAuthentication $backendUser, int $uid): ?array
    {
        $rule = $this->rules->findRule($uid);
        if (null === $rule || 0 !== (int) $rule['deleted'] || !$this->access->mayEdit($backendUser, $rule)) {
            return null;
        }

        return $rule;
    }

    /**
     * @param array<string, mixed> $rule
     *
     * @return array<string, mixed>
     */
    private function present(BackendUserAuthentication $backendUser, array $rule): array
    {
        $uid = (int) $rule['uid'];
        $preset = (string) ($rule['preset'] ?? '');
        $applicable = 0 === (int) ($rule['inapplicable'] ?? 0);
        $base = [
            'uid' => $uid,
            'title' => $this->presetCatalog->displayTitle($rule),
            'active' => 0 === (int) $rule['hidden'],
            'applicable' => $applicable,
            'requirement' => $applicable ? '' : $this->requirementOf($preset),
            'group' => $this->groupOf($rule),
            'origin' => $this->originOf($rule),
        ];
        if (!$this->access->mayEdit($backendUser, $rule)) {
            return $base + ['editable' => false, 'matches' => null, 'problem' => null, 'summary' => null, 'scope' => null, 'values' => null];
        }

        $status = (string) $rule['last_status'];

        return $base + [
            'editable' => true,
            'matches' => $this->matchesOf($backendUser, $rule, $applicable),
            'problem' => '' === $status ? null : $this->validator->problemLabel($status),
            'summary' => $this->ruleSummary->summary($rule),
            'scope' => ['value' => (int) $rule['root_page'], 'label' => $this->scopeLabel($backendUser, (int) $rule['root_page'])],
            'values' => $this->normalize($rule),
        ];
    }

    private function requirementOf(string $preset): string
    {
        $requirement = '' === $preset ? '' : (string) ($this->presetCatalog->find($preset)['requirement'] ?? '');

        return '' !== $requirement ? $requirement : $this->localizationService->translate('LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.openItemPreset.requires.generic');
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function originOf(array $rule): string
    {
        if ('' !== (string) ($rule['preset'] ?? '')) {
            return 'standard';
        }

        return OpenItemRuleRepository::ORIGIN_SUGGESTED === (string) ($rule['origin'] ?? '') ? 'suggested' : 'custom';
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function groupOf(array $rule): string
    {
        return $this->localizationService->translate(self::AREA_LABEL_PREFIX.OpenItemAreaEnumeration::normalize((string) ($rule['area'] ?? '')));
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function matchesOf(BackendUserAuthentication $backendUser, array $rule, bool $applicable): ?int
    {
        if (!$applicable) {
            return null;
        }
        if (0 === (int) $rule['hidden']) {
            return $this->readableMatches($backendUser, (int) $rule['uid']);
        }

        return $this->preview->preview($backendUser, $rule, time())['count'] ?? null;
    }

    private function readableMatches(BackendUserAuthentication $backendUser, int $ruleUid): int
    {
        $count = 0;
        $permsClause = $backendUser->getPagePermsClause(Permission::PAGE_SHOW);
        foreach ($this->items->countPerPageByKeyPrefix(CustomRulesSource::keyPrefix($ruleUid)) as $pageUid => $items) {
            if ($backendUser->isAdmin() || false !== BackendUtility::readPageAccess($pageUid, $permsClause)) {
                $count += $items;
            }
        }

        return $count;
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function scopesFor(BackendUserAuthentication $backendUser, int $currentPage): array
    {
        $scopes = [];
        if ($backendUser->isAdmin()) {
            $scopes[0] = $this->scopeLabel($backendUser, 0);
            foreach ($this->pages->getChildPageRows(0) as $page) {
                $scopes[(int) $page['uid']] = (string) $page['title'];
            }
        } else {
            foreach ($backendUser->getWebmounts() as $mount) {
                if ($this->access->mayUseScope($backendUser, (int) $mount)) {
                    $scopes[(int) $mount] = $this->scopeLabel($backendUser, (int) $mount);
                }
            }
        }

        if ($currentPage > 0 && $this->access->mayUseScope($backendUser, $currentPage)) {
            $title = $this->scopeLabel($backendUser, $currentPage);
            if ('' !== $title) {
                $scopes[$currentPage] ??= $title;
            }
        }

        $options = [];
        foreach ($scopes as $value => $label) {
            $options[] = ['value' => (int) $value, 'label' => $label];
        }

        return $options;
    }

    private function scopeLabel(BackendUserAuthentication $backendUser, int $rootPage): string
    {
        if ($rootPage <= 0) {
            return $this->localizationService->translate(self::LABEL_PREFIX.'scope.all');
        }

        $page = BackendUtility::readPageAccess($rootPage, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));

        return is_array($page) ? (string) ($page['title'] ?? '') : '';
    }
}
