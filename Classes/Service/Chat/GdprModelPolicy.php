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

final class GdprModelPolicy
{
    /**
     * @var list<string>
     */
    private const COMPLIANT_MODELS = ['IonosQwen35'];

    private const DEFAULT_MODEL = 'IonosQwen35';

    public function __construct(
        private readonly ChatSettingsService $chatSettings,
    ) {}

    /**
     * @param array<string, mixed> $extConf
     */
    public function isForced(array $extConf): bool
    {
        if (true === $this->chatSettings->getGdprForcedOverride()) {
            return true;
        }

        return (bool) ($extConf['forceGdpa'] ?? false);
    }

    public function isCompliant(string $model): bool
    {
        return in_array($model, self::COMPLIANT_MODELS, true);
    }

    public function defaultModel(): string
    {
        return self::DEFAULT_MODEL;
    }

    /**
     * @return list<string>
     */
    public function compliantModels(): array
    {
        return self::COMPLIANT_MODELS;
    }
}
