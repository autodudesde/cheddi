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

use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;

class UploadedFileWriter
{
    public const STORED_NAME_PATTERN = '/^[0-9a-f]{16}-/';

    public function write(UploadedFileInterface $uploaded, Folder $folder, bool $randomName): ?File
    {
        $file = $folder->getStorage()->addUploadedFile(
            $uploaded,
            $folder,
            $this->storedName($uploaded, $randomName),
            $this->renameOnConflict(),
        );

        return $file instanceof File ? $file : null;
    }

    public function storedName(UploadedFileInterface $uploaded, bool $randomName): string
    {
        $name = (string) $uploaded->getClientFilename();

        return $randomName ? bin2hex(random_bytes(8)).'-'.$name : $name;
    }

    public function renameOnConflict(): DuplicationBehavior|string
    {
        // v13 moved DuplicationBehavior into the Enum namespace; v12 casts the bare string instead.
        return class_exists(DuplicationBehavior::class) ? DuplicationBehavior::RENAME : 'rename';
    }
}
