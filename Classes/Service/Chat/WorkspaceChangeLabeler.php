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
use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuiteMcp\Mcp\Service\RecordPreviewService;
use AutoDudes\AiSuiteMcp\Mcp\Service\TcaLabelService;
use TYPO3\CMS\Backend\Utility\BackendUtility;

class WorkspaceChangeLabeler
{
    private const POSITION_LABEL = 'LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.review.position';

    public function __construct(
        private readonly RecordPreviewService $recordPreview,
        private readonly TcaLabelService $tcaLabels,
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly LocalizationService $localizationService,
    ) {}

    public function recordLabel(string $table, int $workspaceRecordUid, int $liveUid, string $fallback): string
    {
        foreach ([$workspaceRecordUid, $liveUid] as $uid) {
            if ($uid <= 0) {
                continue;
            }
            $row = BackendUtility::getRecord($table, $uid);
            $label = is_array($row) ? $this->recordPreview->labelFor($table, $row) : '';
            if ('' !== $label) {
                return $label;
            }
        }

        return $fallback;
    }

    /**
     * @param list<string> $fields
     *
     * @return list<string>
     */
    public function fieldLabels(string $table, array $fields): array
    {
        try {
            $positionFields = ['pid', (string) ($this->tcaCompatibilityService->getRawConfiguration($table)['sortby'] ?? '')];
        } catch (\Throwable) {
            $positionFields = ['pid'];
        }

        $labels = [];
        foreach ($fields as $field) {
            $labels[] = in_array($field, $positionFields, true)
                ? $this->localizationService->translate(self::POSITION_LABEL)
                : $this->tcaLabels->getFieldLabel($table, $field);
        }

        return array_values(array_unique($labels));
    }
}
