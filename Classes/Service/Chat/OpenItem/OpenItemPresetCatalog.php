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
use AutoDudes\Cheddi\Enumeration\OpenItemAreaEnumeration;

class OpenItemPresetCatalog
{
    private const LABEL_PREFIX = 'LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.openItemPreset.';

    private const ENTRIES = [
        'pagesWithoutDescription' => [
            'recommended' => false, 'area' => 'seo', 'group' => 'pages', 'source' => '', 'source_table' => 'pages', 'severity' => 'notice',
            'conditions' => [['field_name' => 'doktype', 'comparison' => 'equals', 'compare_value' => '1'], ['field_name' => 'description', 'comparison' => 'is_empty']],
        ],
        'pagesWithoutSeoTitle' => [
            'recommended' => false, 'area' => 'seo', 'group' => 'pages', 'source' => '', 'source_table' => 'pages', 'severity' => 'notice', 'requires' => 'seo',
            'conditions' => [['field_name' => 'doktype', 'comparison' => 'equals', 'compare_value' => '1'], ['field_name' => 'seo_title', 'comparison' => 'is_empty']],
        ],
        'imagesWithoutAlternativeText' => [
            'recommended' => true, 'area' => 'accessibility', 'group' => 'media', 'source' => '', 'source_table' => 'sys_file_reference', 'severity' => 'warning',
            'conditions' => [['field_name' => 'alternative', 'comparison' => 'effective_empty']],
        ],
        'seoErrors' => [
            'recommended' => true, 'area' => 'seo', 'group' => 'audits', 'source' => 'audit', 'source_table' => '', 'severity' => 'error',
            'conditions' => [['field_name' => 'audit_type', 'comparison' => 'equals', 'compare_value' => 'seo'], ['field_name' => 'severity', 'comparison' => 'equals', 'compare_value' => 'error']],
        ],
        'accessibilityErrors' => [
            'recommended' => false, 'area' => 'accessibility', 'group' => 'audits', 'source' => 'audit', 'source_table' => '', 'severity' => 'error',
            'conditions' => [['field_name' => 'audit_type', 'comparison' => 'equals', 'compare_value' => 'a11y'], ['field_name' => 'severity', 'comparison' => 'equals', 'compare_value' => 'error']],
        ],
        'stalePages' => [
            'recommended' => false, 'area' => 'content', 'group' => 'content', 'source' => 'stale_pages', 'source_table' => '', 'severity' => 'notice',
            'conditions' => [['field_name' => 'days', 'comparison' => 'equals', 'compare_value' => '365']],
        ],
        'brokenLinks' => [
            'recommended' => true, 'area' => 'content', 'group' => 'content', 'source' => 'broken_links', 'source_table' => '', 'severity' => 'warning', 'requires' => 'linkvalidator',
            'conditions' => [],
        ],
        'languageGaps' => [
            'recommended' => true, 'area' => 'translations', 'group' => 'translations', 'source' => 'language_gaps', 'source_table' => '', 'severity' => 'notice', 'requires' => 'languages',
            'conditions' => [],
        ],
        'freddiUnansweredQuestions' => [
            'recommended' => true, 'area' => 'content', 'group' => 'content', 'source' => 'freddi_questions', 'source_table' => '', 'severity' => 'notice', 'requires' => 'freddi',
            'conditions' => [['field_name' => 'days', 'comparison' => 'equals', 'compare_value' => '30']],
        ],
    ];

    public function __construct(
        private readonly LocalizationService $localizationService,
    ) {}

    /**
     * @return list<array{identifier: string, recommended: bool, group: string, area: string, label: string, description: string, requirement: string, values: array<string, mixed>}>
     */
    public function all(): array
    {
        $presets = [];
        foreach (array_keys(self::ENTRIES) as $identifier) {
            $presets[] = $this->presetOf($identifier);
        }

        return $presets;
    }

    /**
     * @return null|array{identifier: string, recommended: bool, group: string, area: string, label: string, description: string, requirement: string, values: array<string, mixed>}
     */
    public function find(string $identifier): ?array
    {
        return isset(self::ENTRIES[$identifier]) ? $this->presetOf($identifier) : null;
    }

    public function titleOf(string $identifier): ?string
    {
        return isset(self::ENTRIES[$identifier]) ? $this->translate($identifier.'.title') : null;
    }

    /**
     * @param array<string, mixed> $rule
     */
    public function displayTitle(array $rule): string
    {
        $stored = (string) ($rule['title'] ?? '');

        return 0 !== (int) ($rule['customized'] ?? 0) ? $stored : ($this->titleOf((string) ($rule['preset'] ?? '')) ?? $stored);
    }

    /**
     * @return array{identifier: string, recommended: bool, group: string, area: string, label: string, description: string, requirement: string, values: array<string, mixed>}
     */
    private function presetOf(string $identifier): array
    {
        $entry = self::ENTRIES[$identifier];
        $title = $this->translate($identifier.'.title');
        $requires = (string) ($entry['requires'] ?? '');

        return [
            'identifier' => $identifier,
            'recommended' => $entry['recommended'],
            'group' => $this->translate('group.'.$entry['group']),
            'area' => OpenItemAreaEnumeration::normalize($entry['area']),
            'label' => $title,
            'description' => $this->translate($identifier.'.description'),
            'requirement' => '' === $requires ? '' : $this->translate('requires.'.$requires),
            'values' => [
                'title' => $title,
                'preset' => $identifier,
                'source' => $entry['source'],
                'area' => $entry['area'],
                'source_table' => $entry['source_table'],
                'match_mode' => 'all',
                'severity' => $entry['severity'],
                'conditions' => $entry['conditions'],
            ],
        ];
    }

    private function translate(string $key): string
    {
        return $this->localizationService->translate(self::LABEL_PREFIX.$key);
    }
}
