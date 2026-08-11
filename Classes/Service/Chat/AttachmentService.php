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

use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\DefaultUploadFolderResolver;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;

class AttachmentService
{
    public const MAX_ATTACHMENTS_PER_MESSAGE = 5;

    public const UPLOAD_FOLDER_NAME = 'cheddi';

    private const MAX_BYTES_BY_GROUP = [
        'text' => 5 * 1024 * 1024,
        'pdf' => 50 * 1024 * 1024,
        'office' => 25 * 1024 * 1024,
    ];

    private const EXTENSION_GROUPS = [
        'txt' => 'text', 'json' => 'text', 'xml' => 'text',
        'pdf' => 'pdf',
        'docx' => 'office', 'doc' => 'office', 'odt' => 'office', 'rtf' => 'office',
        'xlsx' => 'office', 'xls' => 'office', 'ods' => 'office',
    ];

    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly DocumentExtractorService $extractor,
        private readonly ConnectionPool $connectionPool,
        private readonly DefaultUploadFolderResolver $uploadFolderResolver,
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

    public function isSupportedExtension(string $extension): bool
    {
        return isset(self::EXTENSION_GROUPS[strtolower($extension)]);
    }

    public function maxUploadBytes(): int
    {
        return max(self::MAX_BYTES_BY_GROUP);
    }

    /**
     * @param list<int> $uids
     *
     * @return list<array{uid: int, name: string, extension: string, readable: bool, reason: string}>
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
     * @return array{uid: int, name: string, extension: string, readable: bool, reason: string}
     */
    public function preview(int $uid): array
    {
        $file = $this->resolveWithFallback($uid);
        if (null === $file) {
            return [
                'uid' => $uid,
                'name' => '',
                'extension' => '',
                'readable' => false,
                'reason' => 'notFound',
            ];
        }

        $extension = strtolower($file->getExtension());
        $group = self::EXTENSION_GROUPS[$extension] ?? null;

        $reason = '';
        if (null === $group) {
            $reason = 'unsupported';
        } elseif (!$this->extractor->canExtract($extension)) {
            $reason = 'libraryMissing';
        } elseif ($file->getSize() > self::MAX_BYTES_BY_GROUP[$group]) {
            $reason = 'oversize';
        }

        return [
            'uid' => $file->getUid(),
            'name' => $file->getName(),
            'extension' => $extension,
            'readable' => '' === $reason,
            'reason' => $reason,
        ];
    }

    public function resolveUploadFolder(BackendUserAuthentication $backendUser): ?Folder
    {
        $default = $this->uploadFolderResolver->resolve($backendUser);
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
        if ($this->isReferencedElsewhere($uid)) {
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

        $fileUid = $this->lookupFileUidFromReference($uid);
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
     * @param list<array{uid: int, name: string, extension: string, readable: bool, reason: string}> $refs
     */
    public function mergeMarkersIntoContent(string $userText, array $refs): string
    {
        if ([] === $refs) {
            return $userText;
        }

        $lines = [];
        foreach ($refs as $ref) {
            $lines[] = $ref['readable']
                ? sprintf('- "%s" (sys_file:%d) — call readAttachmentText with uid=%d to read its text.', $ref['name'], $ref['uid'], $ref['uid'])
                : sprintf('- "%s" (sys_file:%d) — the text of this file cannot be read (%s). Tell the editor rather than guessing its content.', $ref['name'], $ref['uid'], $ref['reason']);
        }

        return $userText."\n\n[Attachments]\n".implode("\n", $lines);
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

        $content = sprintf("# %s (sys_file:%d)\n\n%s", $file->getName(), $file->getUid(), $result['text']);
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

    private function isReferencedElsewhere(int $uid): bool
    {
        $references = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $references->getRestrictions()->removeAll();
        $referenceCount = (int) $references
            ->count('uid')
            ->from('sys_file_reference')
            ->where($references->expr()->eq('uid_local', $references->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchOne()
        ;
        if ($referenceCount > 0) {
            return true;
        }

        $refIndex = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $refIndex->getRestrictions()->removeAll();

        return (int) $refIndex
            ->count('hash')
            ->from('sys_refindex')
            ->where(
                $refIndex->expr()->eq('ref_table', $refIndex->createNamedParameter('sys_file')),
                $refIndex->expr()->eq('ref_uid', $refIndex->createNamedParameter($uid, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchOne() > 0
        ;
    }

    private function lookupFileUidFromReference(int $uid): ?int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        $uidLocal = $queryBuilder
            ->select('uid_local')
            ->from('sys_file_reference')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne()
        ;

        return is_numeric($uidLocal) && (int) $uidLocal > 0 ? (int) $uidLocal : null;
    }
}
