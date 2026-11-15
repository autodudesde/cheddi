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

final class OpenItemRuleProblemEnumeration
{
    public const TABLE_BLOCKED = 'tableBlocked';
    public const NO_CHANGE_DATE = 'noChangeDate';
    public const FIELD_BLOCKED = 'fieldBlocked';
    public const VALUE_MISSING = 'valueMissing';
    public const CONDITION_MISSING = 'conditionMissing';
    public const SOURCE_UNAVAILABLE = 'sourceUnavailable';
    public const FAILED = 'failed';
    public const TOO_MANY_MATCHES = 'tooManyMatches';

    public const ALL = [
        self::TABLE_BLOCKED,
        self::NO_CHANGE_DATE,
        self::FIELD_BLOCKED,
        self::VALUE_MISSING,
        self::CONDITION_MISSING,
        self::SOURCE_UNAVAILABLE,
        self::FAILED,
        self::TOO_MANY_MATCHES,
    ];
}
