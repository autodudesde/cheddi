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
use AutoDudes\AiSuite\Service\ModelService;
use AutoDudes\AiSuite\Service\PromptTemplateService;
use AutoDudes\AiSuite\Service\SiteService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class ChatCatalogService
{
    public function __construct(
        private readonly ModelService $modelService,
        private readonly SettingsFactory $settingsFactory,
        private readonly GdprModelPolicy $gdprModelPolicy,
        private readonly BackendUserService $backendUserService,
        private readonly ChatServerCapabilityService $capabilityService,
        private readonly ChatOrientationService $orientationService,
        private readonly PromptTemplateService $promptTemplateService,
        private readonly SiteService $siteService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{
     *     models: list<array{name: string, label: string, creditsPerMillion: int, isGdpr: bool}>,
     *     orientation: array{gdprForced: bool, writeMode: string, webResearch: bool, sessionLifetimeDays: int},
     * }
     */
    public function getModelCatalog(): array
    {
        $extConf = $this->settingsFactory->mergeExtConfAndUserGroupSettings();
        $modelsWithKeys = $this->modelService->fetchKeysByModelType($extConf, ['chat']);
        $gdprOnly = $this->gdprModelPolicy->isForced($extConf);
        $capabilities = $this->capabilityService->get();
        $rates = $capabilities['rates'];

        $available = [];
        foreach ($modelsWithKeys as $modelName => $keyMap) {
            if (!$this->backendUserService->checkPermissions('tx_aisuite_models:'.$modelName)) {
                continue;
            }
            if ($gdprOnly && !$this->gdprModelPolicy->isCompliant((string) $modelName)) {
                continue;
            }
            if ($capabilities['byok'] && !self::hasApiKey((array) $keyMap)) {
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
     * @return list<array{id: int, iso: string, label: string}>
     */
    public function getAvailableLanguages(int $pageId): array
    {
        $languages = [];
        $seen = [];
        foreach ($this->siteService->getAvailableLanguages(true, $pageId) as $key => $title) {
            $parts = explode('__', (string) $key);
            $languageId = (int) ($parts[1] ?? -1);
            if ($languageId <= 0 || isset($seen[$languageId])) {
                continue;
            }
            $seen[$languageId] = true;
            $languages[] = [
                'id' => $languageId,
                'iso' => (string) ($parts[0] ?? ''),
                'label' => trim((string) preg_replace('/\s*\[[^\]]*\]\s*$/', '', (string) $title)),
            ];
        }

        return $languages;
    }

    /**
     * @param array<string, mixed> $keyMap
     */
    public static function hasApiKey(array $keyMap): bool
    {
        foreach ($keyMap as $value) {
            if ('' !== trim((string) $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{gdprForced: bool, writeMode: string, webResearch: bool, sessionLifetimeDays: int}
     */
    private function resolveOrientation(): array
    {
        try {
            return $this->orientationService->getOrientation();
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: could not resolve operating context, using defaults', [
                'exception' => $e->getMessage(),
            ]);

            return [
                'gdprForced' => false,
                'writeMode' => 'workspace',
                'webResearch' => false,
                'sessionLifetimeDays' => ChatSessionAutoDeleter::DEFAULT_LIFETIME_DAYS,
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
