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

use AutoDudes\AiSuiteMcp\Mcp\Service\MediaUploadSettings;
use AutoDudes\Cheddi\Domain\Repository\FileReferenceRepository;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\DefaultUploadFolderResolver;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;

class AttachmentService
{
    public const MAX_ATTACHMENTS_PER_MESSAGE = 5;

    public const KIND_DOCUMENT = 'document';

    public const KIND_IMAGE = 'image';

    public const UPLOAD_FOLDER_NAME = 'cheddi';

    private const MAX_BYTES_BY_GROUP = [
        'text' => 5 * 1024 * 1024,
        'pdf' => 50 * 1024 * 1024,
        'office' => 25 * 1024 * 1024,
    ];

    private const EXTENSION_GROUPS = [
        'txt' => 'text', 'csv' => 'text', 'md' => 'text', 'json' => 'text', 'xml' => 'text',
        'pdf' => 'pdf',
        'docx' => 'office', 'doc' => 'office', 'odt' => 'office', 'rtf' => 'office',
        'xlsx' => 'office', 'xls' => 'office', 'ods' => 'office',
    ];

    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly DocumentExtractorService $extractor,
        private readonly FileReferenceRepository $fileReferenceRepository,
        private readonly DefaultUploadFolderResolver $uploadFolderResolver,
        private readonly MediaUploadSettings $mediaUploadSettings,
        private readonly ChatSettingsService $chatSettings,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return list<int>
     */
    public function parseClientPayload(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }

        $uids = [];
        foreach ($raw as $entry) {
            $uid = is_array($entry) ? ($entry['uid'] ?? null) : $entry;
            if (is_numeric($uid) && (int) $uid > 0) {
                $uids[] = (int) $uid;
            }
        }

        return array_slice(array_values(array_unique($uids)), 0, self::MAX_ATTACHMENTS_PER_MESSAGE);
    }

    public function isSupportedExtension(string $extension, bool $imagesAllowed): bool
    {
        $extension = strtolower($extension);

        return isset(self::EXTENSION_GROUPS[$extension]) || ($imagesAllowed && $this->isImage($extension));
    }

    public function maxUploadBytes(bool $imagesAllowed): int
    {
        return $imagesAllowed
            ? max(self::MAX_BYTES_BY_GROUP + ['image' => $this->maxImageBytes()])
            : max(self::MAX_BYTES_BY_GROUP);
    }

    public function maxBytesFor(string $extension): ?int
    {
        $extension = strtolower($extension);
        $group = self::EXTENSION_GROUPS[$extension] ?? null;
        if (null !== $group) {
            return self::MAX_BYTES_BY_GROUP[$group];
        }

        return $this->isImage($extension) ? $this->maxImageBytes() : null;
    }

    public function maxAttachmentsPerMessage(): int
    {
        return self::MAX_ATTACHMENTS_PER_MESSAGE;
    }

    public function acceptAttribute(bool $imagesAllowed): string
    {
        $extensions = array_keys(self::EXTENSION_GROUPS);
        if ($imagesAllowed) {
            $extensions = [...$extensions, ...$this->mediaUploadSettings->allowedImageExtensions()];
        }

        return implode(',', array_map(
            static fn (string $extension): string => '.'.$extension,
            $extensions,
        ));
    }

    /**
     * @param list<int> $uids
     *
     * @return list<array{uid: int, name: string, extension: string, kind: string, readable: bool, reason: string}>
     */
    public function normalizeRefs(array $uids): array
    {
        $refs = [];
        foreach ($uids as $uid) {
            $refs[] = $this->preview($uid);
        }

        return $refs;
    }

    /**
     * @return array{uid: int, name: string, extension: string, kind: string, readable: bool, reason: string}
     */
    public function preview(int $uid): array
    {
        $file = $this->resolveWithFallback($uid);
        if (null === $file) {
            return [
                'uid' => $uid,
                'name' => '',
                'extension' => '',
                'kind' => self::KIND_DOCUMENT,
                'readable' => false,
                'reason' => 'notFound',
            ];
        }

        $extension = strtolower($file->getExtension());
        $group = self::EXTENSION_GROUPS[$extension] ?? null;
        $kind = null === $group && $this->isImage($extension) ? self::KIND_IMAGE : self::KIND_DOCUMENT;
        $maxBytes = $this->maxBytesFor($extension);

        $reason = '';
        if (null === $maxBytes) {
            $reason = 'unsupported';
        } elseif (self::KIND_DOCUMENT === $kind && null !== ($unreadable = $this->extractor->unreadableReason($extension))) {
            $reason = $unreadable;
        } elseif ($file->getSize() > $maxBytes) {
            $reason = 'oversize';
        }

        return [
            'uid' => $file->getUid(),
            'name' => $this->displayName($file),
            'extension' => $extension,
            'kind' => $kind,
            'readable' => '' === $reason,
            'reason' => $reason,
        ];
    }

    public function resolveUploadFolder(BackendUserAuthentication $backendUser): ?Folder
    {
        $default = $this->baseUploadFolder($backendUser);
        if (!$default instanceof Folder || !$default->checkActionPermission('write')) {
            return null;
        }

        try {
            return $default->hasFolder(self::UPLOAD_FOLDER_NAME)
                ? $default->getSubfolder(self::UPLOAD_FOLDER_NAME)
                : $default->createFolder(self::UPLOAD_FOLDER_NAME);
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: could not provide the chat upload folder', [
                'folder' => $default->getCombinedIdentifier(),
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function baseUploadFolder(BackendUserAuthentication $backendUser): ?Folder
    {
        $configured = $this->chatSettings->getUploadFolder();
        if ('' === $configured) {
            $default = $this->uploadFolderResolver->resolve($backendUser);

            return $default instanceof Folder ? $default : null;
        }

        return $this->resolveConfiguredFolder($configured);
    }

    public function resolveConfiguredFolder(string $combinedIdentifier): ?Folder
    {
        try {
            return $this->resourceFactory->getFolderObjectFromCombinedIdentifier($combinedIdentifier);
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: the configured chat upload folder does not exist', [
                'folder' => $combinedIdentifier,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function isInChatFolder(int $uid): bool
    {
        try {
            return self::UPLOAD_FOLDER_NAME === $this->resourceFactory->getFileObject($uid)->getParentFolder()->getName();
        } catch (\Throwable) {
            return false;
        }
    }

    public function deleteUploadIfUnused(int $uid): bool
    {
        try {
            $file = $this->resourceFactory->getFileObject($uid);
        } catch (\Throwable) {
            return false;
        }

        if (self::UPLOAD_FOLDER_NAME !== $file->getParentFolder()->getName()) {
            return false;
        }
        if ($this->fileReferenceRepository->isFileReferenced($uid)) {
            return false;
        }

        try {
            return $file->delete();
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: could not delete an expired chat attachment', [
                'file' => $uid,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function resolveWithFallback(int $uid): ?File
    {
        try {
            return $this->authorizeReadable($this->resourceFactory->getFileObject($uid));
        } catch (FileDoesNotExistException|\InvalidArgumentException) {
            // fall through to the reference lookup
        }

        $fileUid = $this->fileReferenceRepository->findFileUidOfReference($uid);
        if (null === $fileUid) {
            return null;
        }

        try {
            return $this->authorizeReadable($this->resourceFactory->getFileObject($fileUid));
        } catch (\Throwable $e) {
            $this->logger->info('ChEddi: attachment could not be resolved', [
                'uid' => $uid,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param list<array{uid: int, name: string, extension: string, kind: string, readable: bool, reason: string}> $refs
     */
    public function mergeMarkersIntoContent(string $userText, array $refs): string
    {
        if ([] === $refs) {
            return $userText;
        }

        $lines = [];
        $anyUnreadable = false;
        foreach ($refs as $ref) {
            $anyUnreadable = $anyUnreadable || !$ref['readable'];
            $lines[] = match (true) {
                !$ref['readable'] => sprintf('- "%s" (sys_file:%d) — the text of this file cannot be read (%s). Tell the editor rather than guessing its content.', $ref['name'], $ref['uid'], $ref['reason']),
                self::KIND_IMAGE === $ref['kind'] => sprintf('- "%s" (image, sys_file:%d)', $ref['name'], $ref['uid']),
                default => sprintf('- "%s" (sys_file:%d) — call readAttachmentText with uid=%d to read its text.', $ref['name'], $ref['uid'], $ref['uid']),
            };
        }
        if ($anyUnreadable) {
            $lines[] = sprintf(
                'File types readable here: %s. Offer the editor to attach the content in one of these, or to paste it into the message as text.',
                implode(', ', $this->readableDocumentExtensions()),
            );
        }

        return $userText."\n\n[Attachments]\n".implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    public function readableDocumentExtensions(): array
    {
        return array_values(array_filter(
            array_keys(self::EXTENSION_GROUPS),
            fn (string $extension): bool => $this->extractor->canExtract($extension),
        ));
    }

    /**
     * @return array{content: string, isError: bool}
     */
    public function readText(int $uid, int $charOffset = 0): array
    {
        $file = $this->resolveWithFallback($uid);
        if (null === $file) {
            return ['content' => sprintf('No file found for uid %d.', $uid), 'isError' => true];
        }

        $extension = strtolower($file->getExtension());
        $group = self::EXTENSION_GROUPS[$extension] ?? null;
        if (null === $group && $this->isImage($extension)) {
            return ['content' => sprintf('"%s" (sys_file:%d) is an image and has no text to read.', $file->getName(), $file->getUid()), 'isError' => true];
        }
        if (null === $group) {
            return ['content' => sprintf('Files of type "%s" cannot be read as text.', $extension), 'isError' => true];
        }
        if ($file->getSize() > self::MAX_BYTES_BY_GROUP[$group]) {
            return ['content' => sprintf('"%s" is too large to read.', $file->getName()), 'isError' => true];
        }

        try {
            $result = $this->extractor->extract($file, $charOffset);
        } catch (\RuntimeException $e) {
            return ['content' => $e->getMessage(), 'isError' => true];
        }

        $content = sprintf("# %s (sys_file:%d)\n\n%s", $this->displayName($file), $file->getUid(), $result['text']);
        if ($result['truncated']) {
            $content .= sprintf(
                "\n\n[Truncated at %d characters. Call readAttachmentText again with charOffset=%d to continue.]",
                DocumentExtractorService::MAX_OUTPUT_CHARS,
                $charOffset + mb_strlen($result['text']),
            );
        }

        return ['content' => $content, 'isError' => false];
    }

    private function authorizeReadable(File $file): ?File
    {
        try {
            $storage = $file->getStorage();
            if (!$storage->isWithinFileMountBoundaries($file) || !$storage->checkFileActionPermission('read', $file)) {
                $this->logger->warning('ChEddi: attachment read denied — outside the user file mounts', [
                    'file' => $file->getUid(),
                ]);

                return null;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: could not evaluate attachment access', [
                'file' => $file->getUid(),
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        return $file;
    }

    private function displayName(File $file): string
    {
        return (string) preg_replace(UploadedFileWriter::STORED_NAME_PATTERN, '', $file->getName());
    }

    private function isImage(string $extension): bool
    {
        return in_array($extension, $this->mediaUploadSettings->allowedImageExtensions(), true);
    }

    private function maxImageBytes(): int
    {
        $maxBytes = $this->mediaUploadSettings->read()['maxBytes'];

        return $maxBytes > 0 ? $maxBytes : max(self::MAX_BYTES_BY_GROUP);
    }
}
