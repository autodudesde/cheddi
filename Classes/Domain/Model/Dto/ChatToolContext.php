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

use Psr\Http\Message\ServerRequestInterface;

final class ChatToolContext
{
    public function __construct(
        public readonly ServerRequestInterface $request,
        public readonly string $sessionUuid = '',
        public readonly string $model = '',
    ) {}
}
