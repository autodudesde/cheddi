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
use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\AiSuiteMcp\Mcp\Service\McpWriteModeResolver;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\SingletonInterface;

class ChatOrientationService implements SingletonInterface
{
    public function __construct(
        private readonly SettingsFactory $settingsFactory,
        private readonly McpWriteModeResolver $writeModeResolver,
        private readonly ChatSettingsService $chatSettings,
        private readonly GdprModelPolicy $gdprModelPolicy,
        private readonly LocalizationService $localizationService,
        private readonly WebResearchPolicy $webResearchPolicy,
    ) {}

    /**
     * @return array{gdprForced: bool, writeMode: string, webResearch: bool, sessionLifetimeDays: int, apiKeyMissing: bool}
     */
    public function getOrientation(): array
    {
        return [
            'gdprForced' => $this->isGdprForced(),
            'writeMode' => $this->getWriteMode(),
            'webResearch' => $this->webResearchPolicy->isSearchAllowed(),
            'sessionLifetimeDays' => $this->sessionLifetimeDays(),
            'apiKeyMissing' => $this->isApiKeyMissing(),
        ];
    }

    public function isApiKeyMissing(): bool
    {
        try {
            $extConf = $this->settingsFactory->mergeExtConfAndUserGroupSettings();
        } catch (\Throwable) {
            return true;
        }

        return '' === trim((string) ($extConf['aiSuiteApiKey'] ?? ''));
    }

    public function getWriteMode(): string
    {
        return $this->chatSettings->getWriteModeOverride() ?? $this->writeModeResolver->getWriteMode();
    }

    public function resolveWorkspaceId(BackendUserAuthentication $backendUser): int
    {
        return $this->writeModeResolver->resolveWorkspaceId($backendUser, null, $this->getWriteMode());
    }

    public function describeForLlm(): string
    {
        $o = $this->getOrientation();

        $gdpr = $this->translate($o['gdprForced'] ? 'compact.active' : 'compact.inactive');
        $writeMode = $this->translate('live' === $o['writeMode'] ? 'compact.writeLive' : 'compact.writeWorkspace');

        return sprintf(
            '%s: %s · %s: %s',
            $this->translate('compact.gdpr'),
            $gdpr,
            $this->translate('compact.writeMode'),
            $writeMode,
        );
    }

    private function isGdprForced(): bool
    {
        try {
            return $this->gdprModelPolicy->isForced($this->settingsFactory->mergeExtConfAndUserGroupSettings());
        } catch (\Throwable) {
            return false;
        }
    }

    private function translate(string $key): string
    {
        try {
            $label = $this->localizationService->translate(
                'LLL:EXT:cheddi/Resources/Private/Language/locallang.xlf:cheddi.orientation.'.$key,
            );
        } catch (\Throwable) {
            $label = '';
        }

        return '' !== $label ? $label : $key;
    }

    private function sessionLifetimeDays(): int
    {
        return $this->chatSettings->getSessionLifetimeDays();
    }
}
