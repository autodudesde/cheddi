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
use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\AiSuiteMcp\Domain\Repository\SysWorkspaceRepository;
use AutoDudes\AiSuiteMcp\Mcp\Service\McpWriteModeResolver;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\SingletonInterface;

class ChatOrientationService implements SingletonInterface
{
    public const TONE_INFO = 'info';
    public const TONE_SUCCESS = 'success';
    public const TONE_WARNING = 'warning';

    public function __construct(
        private readonly SettingsFactory $settingsFactory,
        private readonly McpWriteModeResolver $writeModeResolver,
        private readonly ChatSettingsService $chatSettings,
        private readonly GdprModelPolicy $gdprModelPolicy,
        private readonly LocalizationService $localizationService,
        private readonly WebResearchPolicy $webResearchPolicy,
        private readonly SysWorkspaceRepository $sysWorkspaceRepository,
        private readonly BackendUserService $backendUserService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{gdprForced: bool, writeMode: string, webResearch: bool, webResearchBlockedReason: string, sessionLifetimeDays: int, apiKeyMissing: bool, workspaceId: int, workspaceTitle: string, workspacePending: bool}
     */
    public function getOrientation(): array
    {
        $workspace = $this->resolveWorkspaceForDisplay();

        return [
            'gdprForced' => $this->isGdprForced(),
            'writeMode' => $this->getWriteMode(),
            'webResearch' => $this->webResearchPolicy->isBrokeredSearchAllowed(),
            'webResearchBlockedReason' => $this->webResearchPolicy->brokeredSearchBlockedReason(),
            'sessionLifetimeDays' => $this->sessionLifetimeDays(),
            'apiKeyMissing' => $this->isApiKeyMissing(),
            'workspaceId' => $workspace['id'],
            'workspaceTitle' => $workspace['title'],
            'workspacePending' => $workspace['pending'],
        ];
    }

    /**
     * Reads only: resolveWorkspaceId() would create a workspace when the user has none.
     *
     * @return array{id: int, title: string, pending: bool}
     */
    public function resolveWorkspaceForDisplay(): array
    {
        if ('live' === $this->getWriteMode()) {
            return ['id' => 0, 'title' => '', 'pending' => false];
        }

        try {
            $backendUser = $this->backendUserService->getBackendUser();
            if (null === $backendUser) {
                return ['id' => 0, 'title' => '', 'pending' => true];
            }

            $workspaceId = (int) $backendUser->workspace;
            if ($workspaceId <= 0) {
                $workspaceId = (int) ($this->sysWorkspaceRepository->findUserWorkspaceUid(
                    (int) ($backendUser->user['uid'] ?? 0),
                ) ?? 0);
            }
            if ($workspaceId <= 0) {
                return ['id' => 0, 'title' => '', 'pending' => true];
            }

            return [
                'id' => $workspaceId,
                'title' => $this->sysWorkspaceRepository->findTitlesByUids([$workspaceId])[$workspaceId] ?? '',
                'pending' => false,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: could not resolve the workspace for display', [
                'error' => $e->getMessage(),
            ]);

            return ['id' => 0, 'title' => '', 'pending' => true];
        }
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

    /**
     * @return list<array{tone: string, text: string}>
     */
    public function statements(): array
    {
        $o = $this->getOrientation();

        $statements = [
            'live' === $o['writeMode']
                ? ['tone' => self::TONE_WARNING, 'text' => $this->translate('writeLive')]
                : ['tone' => self::TONE_INFO, 'text' => $this->workspaceStatement($o['workspaceTitle'], $o['workspacePending'])],
            $o['gdprForced']
                ? ['tone' => self::TONE_SUCCESS, 'text' => $this->translate('gdprOn')]
                : ['tone' => self::TONE_INFO, 'text' => $this->translate('gdprOff')],
            [
                'tone' => self::TONE_INFO,
                'text' => $o['webResearch']
                    ? $this->translate('webResearchOn')
                    : $this->translate('webResearchOff.'.($o['webResearchBlockedReason'] ?: 'default')),
            ],
            ['tone' => self::TONE_SUCCESS, 'text' => $this->translate('confirmation')],
        ];

        if ($o['sessionLifetimeDays'] > 0) {
            $statements[] = [
                'tone' => self::TONE_WARNING,
                'text' => strtr($this->translate('retention'), ['{days}' => (string) $o['sessionLifetimeDays']]),
            ];
        }

        return $statements;
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

    private function workspaceStatement(string $workspaceTitle, bool $pending): string
    {
        if ($pending) {
            return $this->translate('writeWorkspacePending');
        }

        if ('' === $workspaceTitle) {
            return $this->translate('writeWorkspace');
        }

        return strtr($this->translate('writeWorkspaceNamed'), ['{workspace}' => $workspaceTitle]);
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
