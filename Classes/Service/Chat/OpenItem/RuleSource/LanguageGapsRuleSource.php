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
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

final class LanguageGapsRuleSource implements OpenItemRuleSource
{
    public const IDENTIFIER = 'language_gaps';
    public const KEY_PREFIX = 'language:';

    private const PAGE_BATCH = 500;
    private const LABEL_SITE = 'LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.openItemRule.source.languageGapsSite';
    private const LABEL_PREFIX = 'LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.openItemRule.source.';

    private ?int $translatedSites = null;

    public function __construct(
        private readonly OpenItemSourceRepository $sources,
        private readonly SiteFinder $siteFinder,
        private readonly LocalizationService $localizationService,
    ) {}

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function isAvailable(): bool
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            if (count($this->enabledTranslations($site->getAllLanguages())) > 0) {
                return true;
            }
        }

        return false;
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
        return $this->localizationService->translate(self::LABEL_PREFIX.'languageGaps');
    }

    public function describeMatch(array $row): string
    {
        return $this->localizationService->translate(self::LABEL_PREFIX.'languageGapsMatch', [$row['message']]);
    }

    public function subgroupOf(array $row): ?array
    {
        $position = strpos($row['item_key'], self::KEY_PREFIX);
        if (false === $position) {
            return null;
        }

        try {
            $site = $this->siteFinder->getSiteByPageId($row['page_uid']);
        } catch (SiteNotFoundException) {
            return null;
        }
        $key = substr($row['item_key'], $position).':'.$site->getIdentifier();
        if ($this->translatedSiteCount() < 2) {
            return ['key' => $key, 'label' => $row['message']];
        }
        $host = $site->getBase()->getHost();

        return ['key' => $key, 'label' => $this->localizationService->translate(self::LABEL_SITE, [$row['message'], '' !== $host ? $host : $site->getIdentifier()])];
    }

    public function matches(array $parameters, int $now, int $limit): array
    {
        $matches = [];
        $afterUid = 0;
        while ([] !== ($pages = $this->sources->findLivePagesAfter($afterUid, self::PAGE_BATCH))) {
            $translated = $this->sources->findTranslatedLanguages(array_column($pages, 'uid'));
            foreach ($pages as $page) {
                $afterUid = $page['uid'];
                foreach ($this->translationLanguagesOf($page['uid']) as $language) {
                    if (in_array($language->getLanguageId(), $translated[$page['uid']] ?? [], true)) {
                        continue;
                    }
                    $matches[] = [
                        'pageUid' => $page['uid'],
                        'key' => self::KEY_PREFIX.$language->getLanguageId(),
                        'label' => $language->getTitle(),
                        'tstamp' => $page['tstamp'],
                        'recordTable' => '',
                        'recordUid' => 0,
                    ];
                    if (count($matches) >= $limit) {
                        return $matches;
                    }
                }
            }
        }

        return $matches;
    }

    private function translatedSiteCount(): int
    {
        if (null === $this->translatedSites) {
            $this->translatedSites = 0;
            foreach ($this->siteFinder->getAllSites() as $site) {
                if (count($this->enabledTranslations($site->getAllLanguages())) > 0) {
                    ++$this->translatedSites;
                }
            }
        }

        return $this->translatedSites;
    }

    /**
     * @return list<SiteLanguage>
     */
    private function translationLanguagesOf(int $pageUid): array
    {
        try {
            return $this->enabledTranslations($this->siteFinder->getSiteByPageId($pageUid)->getAllLanguages());
        } catch (SiteNotFoundException) {
            return [];
        }
    }

    /**
     * @param array<int, SiteLanguage> $languages
     *
     * @return list<SiteLanguage>
     */
    private function enabledTranslations(array $languages): array
    {
        return array_values(array_filter(
            $languages,
            static fn (SiteLanguage $language): bool => $language->isEnabled() && $language->getLanguageId() > 0,
        ));
    }
}
