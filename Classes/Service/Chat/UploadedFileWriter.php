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
    public function write(UploadedFileInterface $uploaded, Folder $folder): ?File
    {
        $file = $folder->getStorage()->addUploadedFile(
            $uploaded,
            $folder,
            (string) $uploaded->getClientFilename(),
            self::renameOnConflict(),
        );

        return $file instanceof File ? $file : null;
    }

    /**
     * @return DuplicationBehavior|string
     */
    public static function renameOnConflict()
    {
        // v13 moved DuplicationBehavior into the Enum namespace; v12 casts the bare string instead.
        return class_exists(DuplicationBehavior::class) ? DuplicationBehavior::RENAME : 'rename';
    }
}
