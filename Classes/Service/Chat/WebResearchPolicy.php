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
use TYPO3\CMS\Core\SingletonInterface;

class WebResearchPolicy implements SingletonInterface
{
    private const WEB_RESEARCH_PERMISSION = 'tx_aisuite_features:enable_web_research';

    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly SettingsFactory $settingsFactory,
        private readonly GdprModelPolicy $gdprModelPolicy,
        private readonly ChatServerCapabilityService $capabilityService,
    ) {}

    public function isSearchAllowed(): bool
    {
        return $this->isPageReadingAllowed()
            && !$this->gdprModelPolicy->isForced($this->settingsFactory->mergeExtConfAndUserGroupSettings())
            && $this->hasWebSearchKey();
    }

    public function isPageReadingAllowed(): bool
    {
        try {
            return $this->backendUserService->checkPermissions(self::WEB_RESEARCH_PERMISSION);
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasWebSearchKey(): bool
    {
        $capabilities = $this->capabilityService->get();
        if (!$capabilities['byok']) {
            return true;
        }

        $configKey = $capabilities['webSearchApiKeyConfigKey'];

        return '' !== $configKey
            && '' !== trim((string) ($this->settingsFactory->mergeExtConfAndUserGroupSettings()[$configKey] ?? ''));
    }
}
