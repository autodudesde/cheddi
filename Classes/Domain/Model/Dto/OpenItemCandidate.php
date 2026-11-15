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

namespace AutoDudes\Cheddi\Domain\Model\Dto;

final class OpenItemCandidate
{
    public function __construct(
        public readonly int $pageUid,
        public readonly string $itemKey,
        public readonly string $severity,
        public readonly string $message,
        public readonly int $referenceTs,
        public readonly string $recordTable = '',
        public readonly int $recordUid = 0,
        public readonly string $area = '',
    ) {}
}
