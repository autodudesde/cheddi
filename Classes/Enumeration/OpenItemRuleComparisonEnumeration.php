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

final class OpenItemRuleComparisonEnumeration
{
    public const OLDER_THAN_DAYS = 'older_than_days';
    public const IS_EMPTY = 'is_empty';
    public const NOT_EMPTY = 'not_empty';
    public const CONTAINS = 'contains';
    public const NOT_CONTAINS = 'not_contains';
    public const EQUALS = 'equals';
    public const NOT_EQUALS = 'not_equals';
    public const GREATER = 'greater';
    public const LESS = 'less';
    public const DATE_PASSED = 'date_passed';
    public const DATE_WITHIN_DAYS = 'date_within_days';
    public const HAS_NO_RELATED = 'has_no_related';
    public const EFFECTIVE_EMPTY = 'effective_empty';

    public const ALL = [
        self::OLDER_THAN_DAYS,
        self::IS_EMPTY,
        self::NOT_EMPTY,
        self::CONTAINS,
        self::NOT_CONTAINS,
        self::EQUALS,
        self::NOT_EQUALS,
        self::GREATER,
        self::LESS,
        self::DATE_PASSED,
        self::DATE_WITHIN_DAYS,
        self::HAS_NO_RELATED,
        self::EFFECTIVE_EMPTY,
    ];

    public const WITH_VALUE = [self::CONTAINS, self::NOT_CONTAINS, self::EQUALS, self::NOT_EQUALS, self::GREATER, self::LESS];

    public const WITH_DAYS = [self::OLDER_THAN_DAYS, self::DATE_WITHIN_DAYS];
}
