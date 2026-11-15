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

class EditorTaskService
{
    private const AUDIT_LABEL = 'LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.audit.task';
    private const MAX_FINDING_LENGTH = 600;

    public function __construct(
        private readonly LocalizationService $localizationService,
    ) {}

    public function auditFinding(int $pageId, string $auditLabel, string $message, string $evidence): string
    {
        $finding = '' === trim($evidence) ? $message : $message.' '.$evidence;

        return $this->localizationService->translate(self::AUDIT_LABEL, [$pageId, $auditLabel, $this->quotable($finding)]);
    }

    private function quotable(string $text): string
    {
        $withoutQuotes = str_replace(['"', '„', '“', '”', '«', '»'], "'", $text);
        $visible = preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $withoutQuotes) ?? '';
        $singleLine = preg_replace('/\s+/u', ' ', $visible) ?? '';

        return mb_substr(trim($singleLine), 0, self::MAX_FINDING_LENGTH);
    }
}
