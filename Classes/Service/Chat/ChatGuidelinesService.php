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
use AutoDudes\AiSuite\Service\GlobalInstructionService;
use AutoDudes\AiSuite\Service\LocalizationService;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

class ChatGuidelinesService
{
    private const MANAGE_PERMISSION = 'tx_aisuite_features:enable_global_instructions_button';
    private const MANAGE_MODULE = 'web_aisuite';
    private const MANAGE_ROUTE = 'web_aisuite.global_instructions';

    public function __construct(
        private readonly GlobalInstructionService $globalInstructionService,
        private readonly LocalizationService $localizationService,
        private readonly BackendUserService $backendUserService,
        private readonly ModuleProvider $moduleProvider,
        private readonly UriBuilder $uriBuilder,
    ) {}

    /**
     * @return array{resolved: bool, sections: list<array{scope: string, label: string, text: string}>, manageUrl: null|string}
     */
    public function forPage(int $pageId): array
    {
        $manageUrl = $this->manageUrl($pageId);
        if (!$this->rootlineResolves($pageId)) {
            return ['resolved' => false, 'sections' => [], 'manageUrl' => $manageUrl];
        }

        $sections = [];
        $general = trim($this->globalInstructionService->buildGlobalInstruction('pages', 'general', $pageId));
        foreach ($this->globalInstructionService->getScopeItems('pages') as $item) {
            $text = 'general' === $item['value']
                ? $general
                : trim($this->globalInstructionService->buildGlobalInstruction('pages', $item['value'], $pageId));
            if ('' === $text || ('general' !== $item['value'] && $text === $general)) {
                continue;
            }
            $sections[] = [
                'scope' => $item['value'],
                'label' => $this->localizationService->translate($item['label']),
                'text' => $text,
            ];
        }

        return ['resolved' => true, 'sections' => $sections, 'manageUrl' => $manageUrl];
    }

    private function rootlineResolves(int $pageId): bool
    {
        try {
            GeneralUtility::makeInstance(RootlineUtility::class, $pageId, '')->get();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function manageUrl(int $pageId): ?string
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (null === $backendUser
            || !$this->backendUserService->checkPermissions(self::MANAGE_PERMISSION)
            || !$this->moduleProvider->accessGranted(self::MANAGE_MODULE, $backendUser)) {
            return null;
        }

        return (string) $this->uriBuilder->buildUriFromRoute(self::MANAGE_ROUTE, ['id' => $pageId]);
    }
}
