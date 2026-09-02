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

use AutoDudes\AiSuite\Factory\SettingsFactory;
use AutoDudes\AiSuite\Service\BackendUserService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

class WebResearchPolicy implements SingletonInterface
{
    public const REASON_NO_PERMISSION = 'noPermission';
    public const REASON_NO_KEY = 'noKey';
    public const REASON_GDPR_NO_PROVIDER = 'gdprNoProvider';
    public const REASON_GDPR_MARKET = 'gdprMarket';
    private const WEB_RESEARCH_PERMISSION = 'tx_aisuite_features:enable_web_research';

    private const MAX_DOMAINS = 10;

    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly SettingsFactory $settingsFactory,
        private readonly GdprModelPolicy $gdprModelPolicy,
        private readonly ChatServerCapabilityService $capabilityService,
        private readonly SiteFinder $siteFinder,
        private readonly ChatSettingsService $chatSettings,
        private readonly LoggerInterface $logger,
    ) {}

    public function isBrokeredSearchAllowed(): bool
    {
        return '' === $this->brokeredSearchBlockedReason();
    }

    public function brokeredSearchBlockedReason(): string
    {
        if (!$this->isPageReadingAllowed()) {
            return self::REASON_NO_PERMISSION;
        }

        $capabilities = $this->capabilityService->get();
        if (!$this->gdprModelPolicy->isForced($this->settingsFactory->mergeExtConfAndUserGroupSettings())) {
            return $this->hasKeyFor($capabilities['webSearchApiKeyConfigKey']) ? '' : self::REASON_NO_KEY;
        }

        if ('' === $capabilities['gdprWebSearchProvider']) {
            return self::REASON_GDPR_NO_PROVIDER;
        }
        $country = (string) ($this->researchScope()['country'] ?? '');
        if (!in_array($country, $capabilities['gdprWebSearchCountries'], true)) {
            return self::REASON_GDPR_MARKET;
        }

        return $this->hasKeyFor($capabilities['gdprWebSearchApiKeyConfigKey']) ? '' : self::REASON_NO_KEY;
    }

    public function webSearchKeyField(): string
    {
        $capabilities = $this->capabilityService->get();
        if (!$capabilities['byok']) {
            return '';
        }

        return $this->gdprModelPolicy->isForced($this->settingsFactory->mergeExtConfAndUserGroupSettings())
            ? $capabilities['gdprWebSearchApiKeyConfigKey']
            : $capabilities['webSearchApiKeyConfigKey'];
    }

    public function isNativeSearchModel(string $model): bool
    {
        return in_array($model, $this->capabilityService->get()['nativeWebSearchModels'], true);
    }

    public function isNativeSearchAllowed(string $model): bool
    {
        return $this->isNativeSearchModel($model)
            && $this->isPageReadingAllowed()
            && !$this->gdprModelPolicy->isForced($this->settingsFactory->mergeExtConfAndUserGroupSettings())
            && $this->hasKeyFor($this->capabilityService->get()['webSearchApiKeyConfigKey']);
    }

    /**
     * @return array<string, mixed>
     */
    public function turnConfiguration(string $model): array
    {
        if (!$this->isNativeSearchAllowed($model)) {
            return ['enabled' => false];
        }

        return ['enabled' => true] + $this->researchScope();
    }

    /**
     * @return array<string, mixed>
     */
    public function researchScope(): array
    {
        $scope = [];

        $allowed = $this->capDomains($this->chatSettings->getWebResearchAllowedDomains(), 'chatWebResearchAllowedDomains');
        $blocked = $this->capDomains($this->chatSettings->getWebResearchBlockedDomains(), 'chatWebResearchBlockedDomains');
        if ([] !== $allowed) {
            $scope['allowedDomains'] = $allowed;
        } elseif ([] !== $blocked) {
            $scope['blockedDomains'] = $blocked;
        }

        $country = $this->chatSettings->getWebResearchCountry();
        if ('' === $country) {
            $country = $this->siteLocale();
        }
        if ('' !== $country) {
            $scope['country'] = $country;
        }

        $maxSearches = $this->chatSettings->getWebResearchMaxSearches();
        if ($maxSearches > 0) {
            $scope['maxSearches'] = $maxSearches;
        }

        return $scope;
    }

    public function isPageReadingAllowed(): bool
    {
        try {
            return $this->backendUserService->checkPermissions(self::WEB_RESEARCH_PERMISSION);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<string> $domains
     *
     * @return list<string>
     */
    private function capDomains(array $domains, string $setting): array
    {
        // The retrieval providers cap their domain filters and drop the surplus without an error.
        if (count($domains) <= self::MAX_DOMAINS) {
            return $domains;
        }

        $this->logger->warning('ChEddi: web research domain list exceeds the provider limit, surplus dropped', [
            'setting' => $setting,
            'limit' => self::MAX_DOMAINS,
            'dropped' => array_slice($domains, self::MAX_DOMAINS),
        ]);

        return array_slice($domains, 0, self::MAX_DOMAINS);
    }

    private function siteLocale(): string
    {
        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                $languageCode = $site->getDefaultLanguage()->getLocale()->getLanguageCode();
                $region = strtoupper((string) $site->getDefaultLanguage()->getLocale()->getCountryCode());
                if ('' !== $region) {
                    return $region;
                }
                if ('' !== $languageCode) {
                    return strtoupper($languageCode);
                }
            }
        } catch (\Throwable) {
            return '';
        }

        return '';
    }

    private function hasKeyFor(string $configKey): bool
    {
        if (!$this->capabilityService->get()['byok']) {
            return true;
        }

        return '' !== $configKey
            && '' !== trim((string) ($this->settingsFactory->mergeExtConfAndUserGroupSettings()[$configKey] ?? ''));
    }
}
