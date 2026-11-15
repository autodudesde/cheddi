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

namespace AutoDudes\Cheddi\Service\Chat\OpenItem;

use AutoDudes\Cheddi\Domain\Model\Dto\OpenItemCandidate;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(self::TAG)]
interface OpenItemSource
{
    public const TAG = 'aisuite.cheddi.open_item_source';

    public function kind(): string;

    /**
     * @return iterable<OpenItemCandidate>
     */
    public function collect(int $now): iterable;

    /**
     * @param array{page_uid: int, item_key: string, message: string, reference_ts: int, record_table?: string, record_uid?: int} $row
     *
     * @return array{group: string, label: string}
     */
    public function describe(array $row, string $pageTitle): array;
}
