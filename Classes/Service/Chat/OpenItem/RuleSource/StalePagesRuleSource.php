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
use AutoDudes\Cheddi\Enumeration\OpenItemRuleProblemEnumeration;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class StalePagesRuleSource implements OpenItemRuleSource
{
    public const IDENTIFIER = 'stale_pages';

    private const DEFAULT_DAYS = 365;
    private const PAGE_BATCH = 500;
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
        return true;
    }

    public function problemOf(array $parameters): ?string
    {
        return $this->days($parameters) > 0 ? null : OpenItemRuleProblemEnumeration::SOURCE_UNAVAILABLE;
    }

    public function mayUse(BackendUserAuthentication $backendUser): bool
    {
        return true;
    }

    public function summary(array $parameters): string
    {
        return $this->localizationService->translate(self::LABEL_PREFIX.'stalePages', [$this->days($parameters)]);
    }

    public function describeMatch(array $row): string
    {
        return $this->localizationService->translate(self::LABEL_PREFIX.'stalePagesMatch', [(string) BackendUtility::date($row['reference_ts'])]);
    }

    public function subgroupOf(array $row): ?array
    {
        return null;
    }

    public function matches(array $parameters, int $now, int $limit): array
    {
        $cutoff = $now - $this->days($parameters) * 86400;
        $matches = [];
        $afterUid = 0;
        while ([] !== ($pages = $this->sources->findLivePagesAfter($afterUid, self::PAGE_BATCH))) {
            $latestContent = $this->sources->findLatestContentChanges(array_column($pages, 'uid'));
            foreach ($pages as $page) {
                $afterUid = $page['uid'];
                $lastChange = max($page['tstamp'], $latestContent[$page['uid']] ?? 0);
                if ($lastChange <= 0 || $lastChange >= $cutoff) {
                    continue;
                }
                $matches[] = ['pageUid' => $page['uid'], 'key' => 'page:'.$page['uid'], 'label' => '', 'tstamp' => $lastChange, 'recordTable' => '', 'recordUid' => 0];
                if (count($matches) >= $limit) {
                    return $matches;
                }
            }
        }

        return $matches;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function days(array $parameters): int
    {
        return '' === ($parameters['days'] ?? '') ? self::DEFAULT_DAYS : (int) $parameters['days'];
    }
}
