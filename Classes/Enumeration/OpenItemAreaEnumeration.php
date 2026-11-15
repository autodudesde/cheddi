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

final class OpenItemAreaEnumeration
{
    public const SEO = 'seo';
    public const ACCESSIBILITY = 'accessibility';
    public const CONTENT = 'content';
    public const TRANSLATIONS = 'translations';
    public const MEDIA = 'media';
    public const FREDDI = 'freddi';
    public const CUSTOM = 'custom';

    public const ALL = [self::SEO, self::ACCESSIBILITY, self::CONTENT, self::TRANSLATIONS, self::MEDIA, self::FREDDI, self::CUSTOM];

    public static function normalize(string $area): string
    {
        return in_array($area, self::ALL, true) ? $area : self::CUSTOM;
    }
}
