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

namespace AutoDudes\Cheddi\Service\Chat\OpenItem\Fix;

use AutoDudes\AiSuite\Factory\SettingsFactory;
use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\Cheddi\Service\Chat\ChatCatalogService;
use AutoDudes\Cheddi\Service\Chat\ChatServerCapabilityService;
use AutoDudes\Cheddi\Service\Chat\GdprModelPolicy;

class OpenItemFixModels
{
    public const ESTIMATED_TOKENS = [OpenItemFixTarget::KIND_TEXT => 4000, OpenItemFixTarget::KIND_IMAGE => 800];

    public function __construct(
        private readonly ChatCatalogService $chatCatalog,
        private readonly ChatServerCapabilityService $capabilities,
        private readonly GdprModelPolicy $gdprModelPolicy,
        private readonly SettingsFactory $settingsFactory,
        private readonly BackendUserService $backendUserService,
    ) {}

    /**
     * @return array{text: list<array{name: string, label: string, creditsPerMillion: int, isGdpr: bool}>, image: null|array{name: string, label: string, creditsPerMillion: int, isGdpr: bool}}
     */
    public function forCurrentUser(): array
    {
        $text = array_map(static fn (array $model): array => [
            'name' => (string) $model['name'],
            'label' => (string) $model['label'],
            'creditsPerMillion' => (int) $model['creditsPerMillion'],
            'isGdpr' => (bool) $model['isGdpr'],
        ], $this->chatCatalog->getModelCatalog()['models']);

        return ['text' => $text, 'image' => $this->imageModel()];
    }

    /**
     * @return null|array{name: string, label: string, creditsPerMillion: int, isGdpr: bool}
     */
    public function imageModel(): ?array
    {
        $capabilities = $this->capabilities->get();
        $gdprForced = $this->gdprModelPolicy->isForced($this->settingsFactory->mergeExtConfAndUserGroupSettings());
        foreach ($capabilities['visionRates'] as $name => $rate) {
            $isGdpr = in_array($name, $capabilities['visionGdprModels'], true);
            if ($gdprForced !== $isGdpr || !$this->backendUserService->checkPermissions('tx_aisuite_models:'.$name)) {
                continue;
            }

            return ['name' => (string) $name, 'label' => (string) $name, 'creditsPerMillion' => (int) $rate, 'isGdpr' => $isGdpr];
        }

        return null;
    }

    public function isAllowedTextModel(string $model): bool
    {
        return in_array($model, array_column($this->forCurrentUser()['text'], 'name'), true);
    }
}
