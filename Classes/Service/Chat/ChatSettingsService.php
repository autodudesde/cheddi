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

use AutoDudes\AiSuite\Service\BackendUserService;
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
        private readonly BackendUserService $backendUserService,
    ) {}

    public function applyToMcpSurface(): void
    {
        if ($this->surfaceOverridesApplied) {
            return;
        }

        $this->surfaceOverrides->apply(
            $this->getRawHtmlWriteOverride(),
            $this->getExcludedTables(),
        );
        $this->surfaceOverridesApplied = true;
    }

    public function getWriteModeOverride(): ?string
    {
        if (in_array('workspace', $this->groupValues('chatWriteMode'), true)) {
            return 'workspace';
        }

        $value = $this->setting('chatWriteMode');

        return ('' === $value || self::INHERIT === $value) ? null : $value;
    }

    public function getRawHtmlWriteOverride(): ?bool
    {
        if (in_array('0', $this->groupValues('chatAllowRawHtmlWrite'), true)) {
            return false;
        }

        return $this->boolOverride('chatAllowRawHtmlWrite');
    }

    public function getGdprForcedOverride(): ?bool
    {
        if (in_array('1', $this->groupValues('chatForceGdpa'), true)) {
            return true;
        }

        return $this->boolOverride('chatForceGdpa');
    }

    /**
     * @return list<string>
     */
    public function getExcludedTools(): array
    {
        return $this->listWithGroups('chatExcludedTools');
    }

    public function getUploadFolder(): string
    {
        return $this->groupValues('chatUploadFolder')[0] ?? trim($this->setting('chatUploadFolder'));
    }

    public function usesRandomUploadNames(): bool
    {
        return '0' !== $this->setting('chatUploadRandomNames')
            || in_array('1', $this->groupValues('chatUploadRandomNames'), true);
    }

    /**
     * @return list<string>
     */
    public function getExcludedTables(): array
    {
        return $this->listWithGroups('chatExcludedTables');
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
    private function listWithGroups(string $key): array
    {
        $entries = $this->list($key);
        foreach ($this->groupValues($key) as $value) {
            $entries = [...$entries, ...$this->splitList($value)];
        }

        return array_values(array_unique($entries));
    }

    /**
     * @return list<string>
     */
    private function groupValues(string $key): array
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (null === $backendUser || $backendUser->isAdmin()) {
            return [];
        }

        $values = [];
        foreach ($backendUser->userGroups as $group) {
            $value = is_array($group) ? trim((string) ($group[$key] ?? '')) : '';
            if ('' !== $value) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function splitList(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /**
     * @return list<string>
     */
    private function list(string $key): array
    {
        return $this->splitList($this->setting($key));
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
