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
use AutoDudes\Cheddi\Domain\Repository\OpenItemRuleRepository;
use AutoDudes\Cheddi\Domain\Repository\OpenItemSourceRepository;
use AutoDudes\Cheddi\Enumeration\OpenItemAreaEnumeration;
use AutoDudes\Cheddi\Enumeration\OpenItemRuleComparisonEnumeration as Comparison;
use AutoDudes\Cheddi\Service\Chat\OpenItem\Fix\OpenItemFixTargets;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

class OpenItemRuleSuggestions
{
    public const PRESET_PREFIX = 'preset:';
    public const PROPOSAL_PREFIX = 'proposal:';
    public const SEO_PAGE_FIELDS = ['description', 'seo_title', 'og_title', 'og_description', 'twitter_title', 'twitter_description', 'abstract', 'keywords'];

    private const SESSION_KEY = 'cheddi_open_item_rule_proposals';
    private const MIN_RECORDS = 10;
    private const MIN_FILL_RATIO = 0.5;
    private const MAX_TABLES = 30;
    private const MAX_FIELDS = 40;
    private const FILL_TYPES = ['input', 'text'];
    private const SKIPPED_RENDER_TYPES = ['inputDateTime', 'inputLink', 'colorpicker'];
    private const SKIPPED_EVAL = '/\b(int|double2|num|date|datetime|time|timesec|year|password|saltedPassword|md5)\b/';
    private const TITLE_LABEL = 'LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.openItemRule.suggest.gapTitle';

    public function __construct(
        private readonly OpenItemRuleRepository $rules,
        private readonly OpenItemRuleAccess $access,
        private readonly OpenItemRulePreview $preview,
        private readonly OpenItemRuleValidator $validator,
        private readonly OpenItemSourceRepository $sources,
        private readonly TcaLabelService $tcaLabels,
        private readonly LocalizationService $localizationService,
        private readonly LoggerInterface $logger,
        private readonly OpenItemPresetCatalog $presetCatalog,
        private readonly OpenItemRuleTables $ruleTables,
    ) {}

    /**
     * @return array{recommended: list<array{id: string, title: string, count: int}>, restorable: list<array{id: string, title: string, count: int}>, gaps: list<array{id: string, title: string, count: int, total: int}>}
     */
    public function standardFor(BackendUserAuthentication $backendUser, int $now): array
    {
        $recommended = [];
        foreach ($this->rules->findAllNotDeleted() as $rule) {
            if ('' === (string) $rule['preset'] || 0 === (int) $rule['hidden'] || 0 !== (int) ($rule['inapplicable'] ?? 0) || !$this->access->mayEdit($backendUser, $rule)) {
                continue;
            }
            $count = (int) ($this->preview->preview($backendUser, $rule, $now)['count'] ?? 0);
            if ($count > 0) {
                $recommended[] = ['id' => self::PRESET_PREFIX.$rule['preset'], 'title' => $this->presetCatalog->displayTitle($rule), 'count' => $count];
            }
        }
        usort($recommended, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $restorable = [];
        foreach ($this->rules->findDeletedPresets() as $rule) {
            if (0 !== (int) ($rule['inapplicable'] ?? 0) || !$this->access->mayEdit($backendUser, $rule)) {
                continue;
            }
            $restorable[] = ['id' => self::PRESET_PREFIX.$rule['preset'], 'title' => $this->presetCatalog->displayTitle($rule), 'count' => (int) ($this->preview->preview($backendUser, $rule, $now)['count'] ?? 0)];
        }

        return ['recommended' => $recommended, 'restorable' => $restorable, 'gaps' => $this->gapsFor($backendUser, $now)];
    }

    /**
     * @return null|array<string, mixed>
     */
    public function proposal(BackendUserAuthentication $backendUser, string $id): ?array
    {
        $stored = $backendUser->getSessionData(self::SESSION_KEY);
        $definition = is_array($stored) ? ($stored[$id] ?? null) : null;

        return is_array($definition) ? $definition : null;
    }

    /**
     * @return list<array{id: string, title: string, count: int, total: int}>
     */
    private function gapsFor(BackendUserAuthentication $backendUser, int $now): array
    {
        $scope = $this->firstScope($backendUser);
        $structure = $this->structureFor($backendUser);
        if (null === $scope || [] === $structure['tables']) {
            $backendUser->setAndSaveSessionData(self::SESSION_KEY, []);

            return [];
        }

        $candidates = $this->localGaps($structure);
        $known = array_flip(array_map(static fn (array $rule): string => OpenItemRuleDefinition::signature($rule), $this->rules->findAllNotDeleted()));
        $records = array_column($structure['tables'], 'records', 'table');
        $stored = [];
        $proposals = [];
        $dropped = [];
        foreach ($candidates as $candidate) {
            $definition = OpenItemRuleDefinition::normalize($candidate + ['source' => '', 'preset' => '', 'root_page' => $scope]);
            $signature = OpenItemRuleDefinition::signature($definition);
            $problem = $this->validator->problemOf($definition);
            $reason = match (true) {
                '' === $definition['title'] => 'untitled',
                isset($known[$signature]) => 'duplicate',
                null !== $problem => 'invalid:'.$problem,
                !$this->access->mayEdit($backendUser, $definition) => 'notAllowed',
                default => null,
            };
            $count = null === $reason ? (int) ($this->preview->preview($backendUser, $definition, $now)['count'] ?? 0) : 0;
            if (null === $reason && 0 === $count) {
                $reason = 'noMatches';
            }
            if (null !== $reason) {
                $dropped[] = ['signature' => $signature, 'reason' => $reason];

                continue;
            }
            $known[$signature] = true;
            $id = self::PROPOSAL_PREFIX.substr(hash('sha256', $signature), 0, 16);
            $stored[$id] = $definition;
            $proposals[] = [
                'id' => $id,
                'title' => $definition['title'],
                'count' => $count,
                'total' => (int) ($records[$definition['source_table']] ?? 0),
            ];
        }
        $backendUser->setAndSaveSessionData(self::SESSION_KEY, $stored);
        if ([] !== $dropped) {
            $this->logger->notice('ChEddi: suggested checks were left out', ['candidates' => count($candidates), 'dropped' => $dropped]);
        }
        usort($proposals, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $proposals;
    }

    /**
     * @param array{tables: list<array<string, mixed>>} $structure
     *
     * @return list<array<string, mixed>>
     */
    private function localGaps(array $structure): array
    {
        $gaps = [];
        foreach ($structure['tables'] as $table) {
            foreach ($table['fields'] as $field) {
                $gaps[] = [
                    'title' => $this->localizationService->translate(self::TITLE_LABEL, [$table['label'], rtrim((string) $field['label'], ': ')]),
                    'area' => 'pages' === $table['table'] && in_array($field['name'], self::SEO_PAGE_FIELDS, true) ? OpenItemAreaEnumeration::SEO : OpenItemAreaEnumeration::CONTENT,
                    'source_table' => $table['table'],
                    'conditions' => [
                        ...('pages' === $table['table'] ? [['field_name' => 'doktype', 'comparison' => Comparison::EQUALS, 'compare_value' => (string) OpenItemRuleTables::STANDARD_DOKTYPE]] : []),
                        ['field_name' => $field['name'], 'comparison' => Comparison::IS_EMPTY],
                    ],
                ];
            }
        }

        return $gaps;
    }

    /**
     * @return array{tables: list<array<string, mixed>>}
     */
    private function structureFor(BackendUserAuthentication $backendUser): array
    {
        $permsClause = $backendUser->getPagePermsClause(Permission::PAGE_SHOW);
        $readable = [];
        $tables = [];
        foreach ($this->access->tablesFor($backendUser) as $table) {
            if (isset(OpenItemFixTargets::IMAGE_TABLES[$table]) || $this->ruleTables->isConfiguration($table)) {
                continue;
            }
            $fields = $this->structureFields($backendUser, $table);
            if ([] === $fields) {
                continue;
            }

            try {
                $rates = $this->sources->fillRates($table, $fields);
            } catch (\Throwable $e) {
                $this->logger->warning('ChEddi: the fill rate of a table could not be read', ['table' => $table, 'exception' => $e->getMessage()]);

                continue;
            }

            $total = 0;
            $empty = [];
            foreach ($rates as $rate) {
                $readable[$rate['page']] ??= $backendUser->isAdmin() || false !== BackendUtility::readPageAccess($rate['page'], $permsClause);
                if (!$readable[$rate['page']]) {
                    continue;
                }
                $total += $rate['total'];
                foreach ($rate['empty'] as $field => $count) {
                    $empty[$field] = ($empty[$field] ?? 0) + $count;
                }
            }
            if ($total < self::MIN_RECORDS) {
                continue;
            }

            $fields = array_slice(array_values(array_filter($fields, fn (string $field): bool => $this->isCandidate($total - ($empty[$field] ?? $total), $total))), 0, self::MAX_FIELDS);
            if ([] === $fields) {
                continue;
            }

            $tables[] = [
                'table' => $table,
                'label' => $this->tcaLabels->getTableLabel($table),
                'records' => $total,
                'fields' => array_map(fn (string $field): array => [
                    'name' => $field,
                    'label' => $this->tcaLabels->getFieldLabel($table, $field),
                    'type' => (string) ($GLOBALS['TCA'][$table]['columns'][$field]['config']['type'] ?? ''),
                ] + (isset($empty[$field]) ? ['filled' => $total - $empty[$field]] : []), $fields),
            ];
        }
        usort($tables, static fn (array $a, array $b): int => $b['records'] <=> $a['records']);

        return ['tables' => array_slice($tables, 0, self::MAX_TABLES)];
    }

    /**
     * @return list<string>
     */
    private function structureFields(BackendUserAuthentication $backendUser, string $table): array
    {
        $shownEverywhere = $this->ruleTables->fieldsShownInEveryType($table);
        $technical = $this->ruleTables->technicalFields($table);

        return array_values(array_filter(
            $this->access->fieldsFor($backendUser, $table),
            function (string $field) use ($table, $shownEverywhere, $technical): bool {
                $config = $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? [];

                return !isset($technical[$field])
                    && !str_starts_with($field, 't3ver_')
                    && $this->isFillField($table, $field)
                    && true !== ($config['readOnly'] ?? false)
                    && (null === $shownEverywhere || isset($shownEverywhere[$field]));
            },
        ));
    }

    private function isCandidate(int $filled, int $total): bool
    {
        return $filled < $total && $filled / max(1, $total) >= self::MIN_FILL_RATIO;
    }

    private function isFillField(string $table, string $field): bool
    {
        $config = $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? [];

        return in_array($config['type'] ?? '', self::FILL_TYPES, true)
            && !in_array($config['renderType'] ?? '', self::SKIPPED_RENDER_TYPES, true)
            && 1 !== preg_match(self::SKIPPED_EVAL, (string) ($config['eval'] ?? ''));
    }

    private function firstScope(BackendUserAuthentication $backendUser): ?int
    {
        if ($backendUser->isAdmin()) {
            return 0;
        }
        foreach ($backendUser->getWebmounts() as $mount) {
            if ($this->access->mayUseScope($backendUser, (int) $mount)) {
                return (int) $mount;
            }
        }

        return null;
    }
}
