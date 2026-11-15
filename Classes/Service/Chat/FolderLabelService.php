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

use TYPO3\CMS\Core\Resource\StorageRepository;

class FolderLabelService
{
    public function __construct(
        private readonly StorageRepository $storageRepository,
    ) {}

    public function readable(string $combinedIdentifier): string
    {
        if (!preg_match('/^(\d+):(.*)$/', $combinedIdentifier, $parts)) {
            return $combinedIdentifier;
        }
        $storage = $this->storageRepository->findByUid((int) $parts[1]);
        $name = null === $storage ? '' : trim($storage->getName());

        return '' === $name ? $combinedIdentifier : $name.'/'.ltrim($parts[2], '/');
    }
}
