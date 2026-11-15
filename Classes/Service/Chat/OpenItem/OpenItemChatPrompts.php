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
use AutoDudes\Cheddi\Domain\Repository\OpenItemRuleRepository;
use AutoDudes\Cheddi\Enumeration\OpenItemRuleComparisonEnumeration as Comparison;
use AutoDudes\Cheddi\Service\Chat\OpenItem\RuleSource\BrokenLinksRuleSource;
use AutoDudes\Cheddi\Service\Chat\OpenItem\RuleSource\StalePagesRuleSource;
use TYPO3\CMS\Backend\Utility\BackendUtility;

class OpenItemChatPrompts
{
    private const LABEL_PREFIX = 'LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.openItems.chatPrompt.';
    private const EMPTY_COMPARISONS = [Comparison::IS_EMPTY, Comparison::EFFECTIVE_EMPTY];

    /**
     * @var array<int, null|array<string, mixed>>
     */
    private array $rules = [];

    public function __construct(
        private readonly OpenItemRuleRepository $ruleRepository,
        private readonly LocalizationService $localizationService,
        private readonly OpenItemRuleSummary $ruleSummary,
        private readonly OpenItemPresetCatalog $presetCatalog,
        private readonly OpenItemRuleTables $ruleTables,
    ) {}

    /**
     * @param array{kind: string, item_key: string, page_uid: int, message: string, reference_ts: int, record_table: string, record_uid: int} $row
     */
    public function forItem(array $row, string $pageTitle): string
    {
        if (CustomRulesSource::KIND !== $row['kind']) {
            return '';
        }
        $rule = $this->ruleOf($row['item_key']);
        if (null === $rule) {
            return '';
        }

        return match ((string) ($rule['source'] ?? '')) {
            BrokenLinksRuleSource::IDENTIFIER => $this->brokenLink($row, $pageTitle),
            StalePagesRuleSource::IDENTIFIER => $this->translate('stalePage', [$pageTitle, $row['page_uid'], (string) BackendUtility::date($row['reference_ts'])]),
            '' => $this->tableRule($rule, $row, $pageTitle),
            default => '',
        };
    }

    /**
     * @param array{page_uid: int, message: string, record_table: string, record_uid: int} $row
     */
    private function brokenLink(array $row, string $pageTitle): string
    {
        $recordTitle = 'pages' === $row['record_table'] ? '' : $this->recordTitle($row['record_table'], $row['record_uid']);

        return '' === $recordTitle
            ? $this->translate('brokenLinkOnPage', [$pageTitle, $row['page_uid'], $row['message']])
            : $this->translate('brokenLink', [$pageTitle, $row['page_uid'], $row['message'], $recordTitle]);
    }

    /**
     * @param array<string, mixed>                                        $rule
     * @param array{page_uid: int, record_table: string, record_uid: int} $row
     */
    private function emptyRichText(array $rule, array $row, string $pageTitle): string
    {
        $table = (string) ($rule['source_table'] ?? '');
        if ($table !== $row['record_table'] || $row['record_uid'] <= 0) {
            return '';
        }
        foreach ($rule['conditions'] ?? [] as $condition) {
            $field = (string) ($condition['field_name'] ?? '');
            $config = $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? [];
            if (!in_array((string) ($condition['comparison'] ?? ''), self::EMPTY_COMPARISONS, true)
                || 'text' !== ($config['type'] ?? '') || !$this->ruleTables->isRichText($table, $field)) {
                continue;
            }
            $fieldLabel = $this->localizationService->translate((string) ($GLOBALS['TCA'][$table]['columns'][$field]['label'] ?? $field));

            return $this->translate('emptyRichText', [
                '' !== $fieldLabel ? $fieldLabel : $field,
                $this->recordTitle($table, $row['record_uid']),
                $table,
                $row['record_uid'],
                $pageTitle,
                $row['page_uid'],
            ]);
        }

        return '';
    }

    /**
     * @param array<string, mixed>                                        $rule
     * @param array{page_uid: int, record_table: string, record_uid: int} $row
     */
    private function tableRule(array $rule, array $row, string $pageTitle): string
    {
        $richText = $this->emptyRichText($rule, $row, $pageTitle);

        return '' !== $richText ? $richText : $this->ownCheck($rule, $row, $pageTitle);
    }

    /**
     * @param array<string, mixed>                                        $rule
     * @param array{page_uid: int, record_table: string, record_uid: int} $row
     */
    private function ownCheck(array $rule, array $row, string $pageTitle): string
    {
        $table = (string) ($rule['source_table'] ?? '');
        if ('' !== (string) ($rule['preset'] ?? '') || '' === $table || $table !== $row['record_table'] || $row['record_uid'] <= 0) {
            return '';
        }
        $check = $this->presetCatalog->displayTitle($rule);
        $condition = $this->ruleSummary->summary($rule);
        if ('pages' === $table) {
            return $this->translate('ownCheckPage', [$check, $condition, $pageTitle, $row['page_uid']]);
        }
        $tableLabel = $this->localizationService->translate((string) ($GLOBALS['TCA'][$table]['ctrl']['title'] ?? $table));

        return $this->translate('ownCheck', [
            $check,
            $condition,
            $this->recordTitle($table, $row['record_uid']),
            '' !== $tableLabel ? $tableLabel : $table,
            $row['record_uid'],
            $pageTitle,
            $row['page_uid'],
        ]);
    }

    private function recordTitle(string $table, int $uid): string
    {
        $record = BackendUtility::getRecord($table, $uid);

        return null === $record ? '' : trim(BackendUtility::getRecordTitle($table, $record));
    }

    /**
     * @param list<int|string> $arguments
     */
    private function translate(string $key, array $arguments): string
    {
        return $this->localizationService->translate(self::LABEL_PREFIX.$key, $arguments);
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
