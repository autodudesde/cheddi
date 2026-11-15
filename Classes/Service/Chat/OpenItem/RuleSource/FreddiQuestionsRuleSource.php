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
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

final class FreddiQuestionsRuleSource implements OpenItemRuleSource
{
    private const MODULE = 'freddi';
    private const DEFAULT_DAYS = 30;
    private const MESSAGE_MAX_LENGTH = 255;

    public function __construct(
        private readonly OpenItemSourceRepository $sources,
        private readonly SiteFinder $siteFinder,
        private readonly ModuleProvider $moduleProvider,
        private readonly LocalizationService $localizationService,
    ) {}

    public function identifier(): string
    {
        return 'freddi_questions';
    }

    public function isAvailable(): bool
    {
        return ExtensionManagementUtility::isLoaded('freddi');
    }

    public function problemOf(array $parameters): ?string
    {
        return null;
    }

    public function mayUse(BackendUserAuthentication $backendUser): bool
    {
        return $this->moduleProvider->accessGranted(self::MODULE, $backendUser);
    }

    public function summary(array $parameters): string
    {
        return $this->localizationService->translate('LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.openItemRule.source.freddiQuestions', [$this->days($parameters)]);
    }

    public function describeMatch(array $row): string
    {
        return $row['message'];
    }

    public function subgroupOf(array $row): ?array
    {
        return null;
    }

    public function matches(array $parameters, int $now, int $limit): array
    {
        $roots = [];
        $matches = [];
        foreach ($this->sources->findUnansweredQuestions($now - $this->days($parameters) * 86400, $limit) as $question) {
            if (!array_key_exists($question['site'], $roots)) {
                try {
                    $roots[$question['site']] = $this->siteFinder->getSiteByIdentifier($question['site'])->getRootPageId();
                } catch (SiteNotFoundException) {
                    $roots[$question['site']] = null;
                }
            }
            if (null === $roots[$question['site']]) {
                continue;
            }
            $matches[] = [
                'pageUid' => $roots[$question['site']],
                'key' => $question['hash'],
                'label' => mb_substr($question['question'], 0, self::MESSAGE_MAX_LENGTH),
                'tstamp' => $question['crdate'],
                'recordTable' => '',
                'recordUid' => 0,
            ];
        }

        return $matches;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function days(array $parameters): int
    {
        $days = (int) ($parameters['days'] ?? 0);

        return $days > 0 ? $days : self::DEFAULT_DAYS;
    }
}
