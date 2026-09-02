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
use AutoDudes\AiSuite\Service\ModelService;
use AutoDudes\AiSuite\Service\PromptTemplateService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class ChatCatalogService
{
    public function __construct(
        private readonly ModelService $modelService,
        private readonly SettingsFactory $settingsFactory,
        private readonly GdprModelPolicy $gdprModelPolicy,
        private readonly ChatModelPolicy $modelPolicy,
        private readonly ChatServerCapabilityService $capabilityService,
        private readonly ChatOrientationService $orientationService,
        private readonly PromptTemplateService $promptTemplateService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{
     *     models: list<array{name: string, label: string, creditsPerMillion: int, isGdpr: bool}>,
     *     orientation: array{gdprForced: bool, writeMode: string, webResearch: bool, webResearchBlockedReason: string, sessionLifetimeDays: int, apiKeyMissing?: bool, workspaceId: int, workspaceTitle: string, statements: list<array{tone: string, text: string}>},
     * }
     */
    public function getModelCatalog(): array
    {
        $extConf = $this->settingsFactory->mergeExtConfAndUserGroupSettings();
        $modelsWithKeys = $this->modelService->fetchKeysByModelType($extConf, ['chat']);
        $rates = $this->capabilityService->get()['rates'];

        $available = [];
        foreach ($modelsWithKeys as $modelName => $keyMap) {
            if (null !== $this->modelPolicy->reasonFor((string) $modelName, (array) $keyMap, $extConf)) {
                continue;
            }
            $available[] = [
                'name' => (string) $modelName,
                'label' => $this->modelLabel((string) $modelName),
                'creditsPerMillion' => (int) ($rates[$modelName] ?? 0),
                'isGdpr' => $this->gdprModelPolicy->isCompliant((string) $modelName),
            ];
        }

        return [
            'models' => $available,
            'orientation' => $this->resolveOrientation(),
        ];
    }

    /**
     * @return list<array{name: string, prompt: string}>
     */
    public function getStarterTemplates(): array
    {
        $templates = [];
        foreach ($this->promptTemplateService->getAllPromptTemplates('cheddi') as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $prompt = trim((string) ($row['prompt'] ?? ''));
            if ('' === $name || '' === $prompt) {
                continue;
            }
            $templates[] = ['name' => $name, 'prompt' => $prompt];
        }

        return $templates;
    }

    /**
     * @return array{gdprForced: bool, writeMode: string, webResearch: bool, webResearchBlockedReason: string, sessionLifetimeDays: int, apiKeyMissing?: bool, workspaceId: int, workspaceTitle: string, workspacePending: bool, statements: list<array{tone: string, text: string}>}
     */
    private function resolveOrientation(): array
    {
        try {
            return [
                ...$this->orientationService->getOrientation(),
                'statements' => $this->orientationService->statements(),
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: could not resolve operating context, using defaults', [
                'exception' => $e->getMessage(),
            ]);

            return [
                'gdprForced' => false,
                'writeMode' => 'workspace',
                'webResearch' => false,
                'webResearchBlockedReason' => '',
                'sessionLifetimeDays' => ChatSessionAutoDeleter::DEFAULT_LIFETIME_DAYS,
                'workspaceId' => 0,
                'workspaceTitle' => '',
                'workspacePending' => true,
                'statements' => [],
            ];
        }
    }

    private function modelLabel(string $modelName): string
    {
        try {
            $label = LocalizationUtility::translate(
                'LLL:EXT:cheddi/Resources/Private/Language/locallang_tca.xlf:cheddi.model.'.$modelName.'.label',
            );
        } catch (\Throwable) {
            $label = null;
        }

        return is_string($label) && '' !== $label ? $label : $modelName;
    }
}
