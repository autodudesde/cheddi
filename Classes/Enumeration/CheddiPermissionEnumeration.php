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

namespace AutoDudes\Cheddi\Enumeration;

final class CheddiPermissionEnumeration
{
    public const FEATURES = 'tx_cheddi_features';
    public const MODELS = 'tx_cheddi_models';

    public const INTERFACE = self::FEATURES.':enable_cheddi_interface';
    public const WEB_RESEARCH = self::FEATURES.':enable_web_research';
    public const GENERATION = self::FEATURES.':enable_cheddi_generation';
    public const TRANSLATION = self::FEATURES.':enable_cheddi_translation';
    public const PAGE_TRANSLATION = self::FEATURES.':enable_cheddi_page_translation';
    public const FILE_METADATA_TRANSLATION = self::FEATURES.':enable_cheddi_file_metadata_translation';
    public const IMAGE_GENERATION = self::FEATURES.':enable_cheddi_image_generation';
    public const AUDIT = self::FEATURES.':enable_cheddi_audit';
    public const RENDERED_PAGE_READ = self::FEATURES.':enable_cheddi_rendered_page_read';
    public const MEDIA_UPLOAD = self::FEATURES.':enable_cheddi_media_upload';

    public const CHAT_MODELS = ['OpenAiLuna', 'IonosQwen35', 'IonosQwen38', 'ClaudeHaiku55', 'ClaudeSonnet55'];

    public static function model(string $model): string
    {
        return self::MODELS.':'.$model;
    }
}
