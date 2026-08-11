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

namespace AutoDudes\Cheddi\Service\Chat;

use AutoDudes\AiSuite\Service\LocalizationService;

class ChatHelpService
{
    private const LLL = 'LLL:EXT:cheddi/Resources/Private/Language/locallang_help.xlf:cheddi.help.';

    /**
     * @var array<string, list<string>>
     */
    private const SECTIONS = [
        'what' => ['p1', 'i1', 'i2', 'i3', 'i4', 'i5'],
        'flow' => ['p1', 'i1', 'i2', 'i3', 'i4'],
        'naming' => ['p1', 'p2'],
        'write' => ['p1', 'i1', 'i2', 'p2'],
        'privacy' => ['i1', 'i2', 'i3', 'i4'],
        'credits' => ['i1', 'i2', 'i3'],
        'attachments' => ['p1', 'i1', 'i2'],
        'limits' => ['i1', 'i2', 'i3', 'i4'],
    ];

    public function __construct(
        private readonly LocalizationService $localizationService,
        private readonly ChatOrientationService $orientationService,
    ) {}

    /**
     * @return array{title: string, sections: list<array{title: string, blocks: list<array{type: string, text: string}>}>}
     */
    public function getHelp(): array
    {
        $replacements = $this->replacements();

        $sections = [];
        foreach (self::SECTIONS as $section => $keys) {
            $blocks = [];
            foreach ($keys as $key) {
                $text = $this->translate($section.'.'.$key, $replacements);
                if ('' === $text) {
                    continue;
                }
                $blocks[] = [
                    'type' => str_starts_with($key, 'i') ? 'item' : 'paragraph',
                    'text' => $text,
                ];
            }
            if ([] === $blocks) {
                continue;
            }
            $sections[] = [
                'title' => $this->translate($section.'.title'),
                'blocks' => $blocks,
            ];
        }

        return [
            'title' => $this->translate('title'),
            'sections' => $sections,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function replacements(): array
    {
        $orientation = $this->orientationService->getOrientation();

        return [
            '{mode}' => $this->translate(match ($orientation['writeMode']) {
                'live' => 'write.modeLive',
                default => 'write.modeWorkspace',
            }),
            '{days}' => (string) $orientation['sessionLifetimeDays'],
        ];
    }

    /**
     * @param array<string, string> $replacements
     */
    private function translate(string $key, array $replacements = []): string
    {
        try {
            $label = $this->localizationService->translate(self::LLL.$key);
        } catch (\Throwable) {
            return '';
        }

        return [] === $replacements ? $label : strtr($label, $replacements);
    }
}
