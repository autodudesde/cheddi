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

use AutoDudes\AiSuiteMcp\Mcp\Utility\RecordsArgumentDecoder;
use AutoDudes\Cheddi\Domain\Repository\ChatUploadRepository;
use AutoDudes\Cheddi\Domain\Repository\FileReferenceRepository;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;

class AttachmentNameRestorer
{
    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly ChatUploadRepository $uploads,
        private readonly LoggerInterface $logger,
        private readonly FileReferenceRepository $fileReferences,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<int>
     */
    public function referencedUploads(string $toolName, array $arguments): array
    {
        try {
            $uids = match ($toolName) {
                'writeRecords' => $this->uidLocalsIn(RecordsArgumentDecoder::decode($arguments['records'] ?? [])),
                'replaceMediaReference' => [is_numeric($arguments['fileUid'] ?? null) ? (int) $arguments['fileUid'] : 0],
                default => [],
            };
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_unique(array_filter($uids, static fn (int $uid): bool => $uid > 0)));
    }

    /**
     * @return null|array{name: non-empty-string, folder: Folder}
     */
    public function placementForReference(File $file): ?array
    {
        $chatFolder = $file->getParentFolder();
        if (AttachmentService::UPLOAD_FOLDER_NAME !== $chatFolder->getName() || !$this->uploads->isRegistered((int) $file->getUid())) {
            return null;
        }
        $target = $chatFolder->getParentFolder();
        if ($target->getCombinedIdentifier() === $chatFolder->getCombinedIdentifier()) {
            return null;
        }
        $name = (string) preg_replace(UploadedFileWriter::STORED_NAME_PATTERN, '', $file->getName());

        return '' === $name ? null : ['name' => $name, 'folder' => $target];
    }

    /**
     * @param list<int> $fileUids
     *
     * @return array<int, array{name: non-empty-string, folder: string}>
     */
    public function releaseBeforeReference(array $fileUids): array
    {
        $released = [];
        foreach ($fileUids as $fileUid) {
            try {
                $file = $this->resourceFactory->getFileObject($fileUid);
                $placement = $this->placementForReference($file);
                $storedName = $file->getName();
                if (null === $placement || '' === $storedName) {
                    continue;
                }
                $origin = $file->getParentFolder()->getCombinedIdentifier();
                $file->moveTo($placement['folder'], $placement['name']);
                $released[$fileUid] = ['name' => $storedName, 'folder' => $origin];
            } catch (\Throwable $e) {
                $this->logger->warning('ChEddi: could not take an attachment out of the chat folder before referencing it', [
                    'file' => $fileUid,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $released;
    }

    /**
     * @param array<int, array{name: non-empty-string, folder: string}> $released
     */
    public function undoWhereNotReferenced(array $released): void
    {
        foreach ($released as $fileUid => $origin) {
            try {
                if ($this->fileReferences->isFileReferenced($fileUid)) {
                    continue;
                }
                $this->resourceFactory->getFileObject($fileUid)->moveTo(
                    $this->resourceFactory->getFolderObjectFromCombinedIdentifier($origin['folder']),
                    $origin['name'],
                );
            } catch (\Throwable $e) {
                $this->logger->warning('ChEddi: could not return an unreferenced attachment to the chat folder', [
                    'file' => $fileUid,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return null|non-empty-string
     */
    public function nameAfterMove(File $file, string $targetFolder): ?string
    {
        if (AttachmentService::UPLOAD_FOLDER_NAME !== $file->getParentFolder()->getName()
            || AttachmentService::UPLOAD_FOLDER_NAME === $this->folderName($targetFolder)
            || !$this->uploads->isRegistered((int) $file->getUid())
        ) {
            return null;
        }

        $name = (string) preg_replace(UploadedFileWriter::STORED_NAME_PATTERN, '', $file->getName());

        return '' === $name || $name === $file->getName() ? null : $name;
    }

    /**
     * @param list<int> $fileUids
     *
     * @return array<int, non-empty-string>
     */
    public function restoreBeforeMove(array $fileUids, string $targetFolder): array
    {
        $storedNames = [];
        foreach ($fileUids as $fileUid) {
            try {
                $file = $this->resourceFactory->getFileObject($fileUid);
                $name = $this->nameAfterMove($file, $targetFolder);
                if (null === $name) {
                    continue;
                }
                $storedName = $file->getName();
                if ('' === $storedName) {
                    continue;
                }
                $file->rename($name);
                $storedNames[$fileUid] = $storedName;
            } catch (\Throwable $e) {
                $this->logger->warning('ChEddi: could not restore the original name of an attachment before moving it', [
                    'file' => $fileUid,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $storedNames;
    }

    /**
     * @param array<int, non-empty-string> $storedNames
     */
    public function undoWhereNotMoved(array $storedNames): void
    {
        foreach ($storedNames as $fileUid => $storedName) {
            try {
                $file = $this->resourceFactory->getFileObject($fileUid);
                if (AttachmentService::UPLOAD_FOLDER_NAME === $file->getParentFolder()->getName()) {
                    $file->rename($storedName);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('ChEddi: could not give an attachment that was not moved its stored name back', [
                    'file' => $fileUid,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return list<int>
     */
    private function uidLocalsIn(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $uids = [];
        foreach ($value as $key => $item) {
            if ('uid_local' === $key && is_numeric($item)) {
                $uids[] = (int) $item;
            } elseif (is_array($item)) {
                $uids = [...$uids, ...$this->uidLocalsIn($item)];
            }
        }

        return $uids;
    }

    private function folderName(string $combinedIdentifier): string
    {
        $separator = strpos($combinedIdentifier, ':');
        $path = false === $separator ? $combinedIdentifier : substr($combinedIdentifier, $separator + 1);

        return basename(trim($path, '/'));
    }
}
