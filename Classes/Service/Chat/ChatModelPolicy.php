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

class ChatModelPolicy
{
    public const REASON_NOT_CHAT = 'modelNotChat';
    public const REASON_NOT_PERMITTED = 'modelNotPermitted';
    public const REASON_GDPR_BLOCKED = 'gdprModelBlocked';
    public const REASON_MISSING_KEY = 'missingAiModelApiKey';

    public function __construct(
        private readonly ModelService $modelService,
        private readonly SettingsFactory $settingsFactory,
        private readonly GdprModelPolicy $gdprModelPolicy,
        private readonly BackendUserService $backendUserService,
        private readonly ChatServerCapabilityService $capabilityService,
    ) {}

    public function isSelectable(string $model): bool
    {
        return null === $this->reasonNotSelectable($model);
    }

    public function reasonNotSelectable(string $model): ?string
    {
        if ('' === $model) {
            return null;
        }

        $extConf = $this->settingsFactory->mergeExtConfAndUserGroupSettings();
        $keyMap = $this->modelService->fetchKeysByModelType($extConf, ['chat'])[$model] ?? null;

        return $this->reasonFor($model, $keyMap, $extConf);
    }

    /**
     * @param null|array<string, mixed> $keyMap
     * @param array<string, mixed>      $extConf
     */
    public function reasonFor(string $model, ?array $keyMap, array $extConf): ?string
    {
        if (null === $keyMap) {
            return self::REASON_NOT_CHAT;
        }
        if (!$this->backendUserService->checkPermissions('tx_aisuite_models:'.$model)) {
            return self::REASON_NOT_PERMITTED;
        }
        if ($this->gdprModelPolicy->isForced($extConf) && !$this->gdprModelPolicy->isCompliant($model)) {
            return self::REASON_GDPR_BLOCKED;
        }
        if ($this->capabilityService->get()['byok'] && !self::hasApiKey($keyMap)) {
            return self::REASON_MISSING_KEY;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $keyMap
     */
    private static function hasApiKey(array $keyMap): bool
    {
        foreach ($keyMap as $value) {
            if ('' !== trim((string) $value)) {
                return true;
            }
        }

        return false;
    }
}
