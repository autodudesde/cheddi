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

namespace AutoDudes\Cheddi\Service\Chat\OpenItem\RuleSource;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

#[AutoconfigureTag(self::TAG)]
interface OpenItemRuleSource
{
    public const TAG = 'aisuite.cheddi.open_item_rule_source';

    public function identifier(): string;

    public function isAvailable(): bool;

    /**
     * @param array<string, string> $parameters
     */
    public function problemOf(array $parameters): ?string;

    public function mayUse(BackendUserAuthentication $backendUser): bool;

    /**
     * @param array<string, string> $parameters
     */
    public function summary(array $parameters): string;

    /**
     * @param array{message: string, reference_ts: int} $row
     */
    public function describeMatch(array $row): string;

    /**
     * @param array{item_key: string, message: string, page_uid: int} $row
     *
     * @return null|array{key: string, label: string}
     */
    public function subgroupOf(array $row): ?array;

    /**
     * @param array<string, string> $parameters
     *
     * @return list<array{pageUid: int, key: string, label: string, tstamp: int, recordTable: string, recordUid: int}>
     */
    public function matches(array $parameters, int $now, int $limit): array;
}
