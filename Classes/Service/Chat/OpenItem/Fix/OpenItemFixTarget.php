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

final class OpenItemFixTarget
{
    public const KIND_TEXT = 'text';
    public const KIND_IMAGE = 'image';

    public function __construct(
        public readonly string $table,
        public readonly int $uid,
        public readonly string $field,
        public readonly string $kind,
        public readonly int $pageUid,
        public readonly int $fileUid = 0,
    ) {}
}
