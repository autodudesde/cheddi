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

namespace AutoDudes\Cheddi\Service\Chat\OpenItem\RuleSource;

use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\Cheddi\Domain\Repository\OpenItemSourceRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

final class BrokenLinksRuleSource implements OpenItemRuleSource
{
    public const IDENTIFIER = 'broken_links';

    private const BATCH = 500;
    private const LABEL_PREFIX = 'LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.openItemRule.source.';

    public function __construct(
        private readonly OpenItemSourceRepository $sources,
        private readonly LocalizationService $localizationService,
    ) {}

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function isAvailable(): bool
    {
        return ExtensionManagementUtility::isLoaded('linkvalidator');
    }

    public function problemOf(array $parameters): ?string
    {
        return null;
    }

    public function mayUse(BackendUserAuthentication $backendUser): bool
    {
        return true;
    }

    public function summary(array $parameters): string
    {
        return $this->localizationService->translate(self::LABEL_PREFIX.'brokenLinks');
    }

    public function describeMatch(array $row): string
    {
        return $this->localizationService->translate(self::LABEL_PREFIX.'brokenLinksMatch', [$row['message']]);
    }

    public function subgroupOf(array $row): ?array
    {
        return null;
    }

    public function matches(array $parameters, int $now, int $limit): array
    {
        $matches = [];
        $afterUid = 0;
        while ([] !== ($links = $this->sources->findBrokenLinksAfter($afterUid, self::BATCH))) {
            foreach ($links as $link) {
                $afterUid = $link['uid'];
                $pageUid = 'pages' === $link['table_name'] ? $link['record_uid'] : $link['record_pid'];
                if ($pageUid <= 0) {
                    continue;
                }
                $matches[] = [
                    'pageUid' => $pageUid,
                    'key' => sha1($link['table_name'].':'.$link['record_uid'].':'.$link['field'].':'.$link['url']),
                    'label' => $link['url'],
                    'tstamp' => $link['last_check'],
                    'recordTable' => $link['table_name'],
                    'recordUid' => $link['record_uid'],
                ];
                if (count($matches) >= $limit) {
                    return $matches;
                }
            }
        }

        return $matches;
    }
}
