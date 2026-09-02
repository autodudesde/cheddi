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

use AutoDudes\AiSuiteMcp\Mcp\Service\SurfaceSettingOverrides;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

class ChatSettingsService implements SingletonInterface
{
    public const INHERIT = 'inherit';

    private bool $surfaceOverridesApplied = false;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly SurfaceSettingOverrides $surfaceOverrides,
    ) {}

    public function applyToMcpSurface(): void
    {
        if ($this->surfaceOverridesApplied) {
            return;
        }

        $this->surfaceOverrides->apply(
            $this->getRawHtmlWriteOverride(),
            $this->getExcludedTables(),
            $this->getSearchAdditionalTables(),
            $this->getSearchTablesExcludedFromAuto(),
        );
        $this->surfaceOverridesApplied = true;
    }

    public function getWriteModeOverride(): ?string
    {
        $value = $this->setting('chatWriteMode');

        return ('' === $value || self::INHERIT === $value) ? null : $value;
    }

    public function getRawHtmlWriteOverride(): ?bool
    {
        return $this->boolOverride('chatAllowRawHtmlWrite');
    }

    public function getGdprForcedOverride(): ?bool
    {
        return $this->boolOverride('chatForceGdpa');
    }

    /**
     * @return list<string>
     */
    public function getExcludedTools(): array
    {
        return $this->list('chatExcludedTools');
    }

    /**
     * @return list<string>
     */
    public function getExcludedTables(): array
    {
        return $this->list('chatExcludedTables');
    }

    /**
     * @return list<string>
     */
    public function getSearchAdditionalTables(): array
    {
        return $this->list('chatSearchAdditionalTables');
    }

    /**
     * @return list<string>
     */
    public function getSearchTablesExcludedFromAuto(): array
    {
        return $this->list('chatExcludeAdditionalTablesFromSearch');
    }

    public function getSessionLifetimeDays(): int
    {
        $value = (int) $this->setting('chatSessionLifetimeDays');

        return $value > 0 ? $value : ChatSessionAutoDeleter::DEFAULT_LIFETIME_DAYS;
    }

    /**
     * @return list<string>
     */
    public function getWebResearchAllowedDomains(): array
    {
        return $this->list('chatWebResearchAllowedDomains');
    }

    /**
     * @return list<string>
     */
    public function getWebResearchBlockedDomains(): array
    {
        return $this->list('chatWebResearchBlockedDomains');
    }

    public function getWebResearchCountry(): string
    {
        return strtoupper($this->setting('chatWebResearchCountry'));
    }

    public function getWebResearchMaxSearches(): int
    {
        return (int) $this->setting('chatWebResearchMaxSearches');
    }

    private function boolOverride(string $key): ?bool
    {
        $value = $this->setting($key);

        return ('' === $value || self::INHERIT === $value) ? null : (bool) (int) $value;
    }

    /**
     * @return list<string>
     */
    private function list(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->setting($key)))));
    }

    private function setting(string $key): string
    {
        try {
            $value = $this->extensionConfiguration->get('cheddi')[$key] ?? '';
        } catch (\Throwable) {
            return '';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
