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

use AutoDudes\Cheddi\Domain\Repository\ChatChangeRepository;
use AutoDudes\Cheddi\Domain\Repository\ChatMessageRepository;
use AutoDudes\Cheddi\Domain\Repository\ChatSessionRepository;
use AutoDudes\Cheddi\Domain\Repository\ChatUploadRepository;
use Psr\Log\LoggerInterface;

class ChatSessionAutoDeleter
{
    public const DEFAULT_LIFETIME_DAYS = 20;
    public const HARD_DELETE_GRACE_DAYS = 7;
    private const SECONDS_PER_DAY = 86400;

    public function __construct(
        private readonly ChatSessionRepository $sessionRepository,
        private readonly ChatMessageRepository $messageRepository,
        private readonly ChatChangeRepository $changeRepository,
        private readonly ChatUploadRepository $uploadRepository,
        private readonly AttachmentService $attachmentService,
        private readonly ChatSettingsService $chatSettings,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{softDeleted: int, hardDeleted: int}
     */
    public function run(?int $now = null): array
    {
        $now ??= time();
        $lifetimeDays = $this->chatSettings->getSessionLifetimeDays();

        $softCutoff = $now - ($lifetimeDays * self::SECONDS_PER_DAY);
        $hardCutoff = $now - (self::HARD_DELETE_GRACE_DAYS * self::SECONDS_PER_DAY);

        $softCount = 0;
        foreach ($this->sessionRepository->findActiveExpiredUids($softCutoff) as $uid) {
            $this->sessionRepository->softDelete($uid);
            ++$softCount;
        }

        $hardCount = 0;
        $deletedFiles = 0;
        foreach ($this->sessionRepository->findSoftDeletedUidsOlderThan($hardCutoff) as $uid) {
            // Read the attachment references while the messages that carry them still exist.
            $attachmentUids = $this->messageRepository->findAttachmentUidsBySession($uid);

            $this->messageRepository->hardDeleteBySession($uid);
            $this->changeRepository->removeBySession($uid);
            $this->sessionRepository->hardDelete($uid);
            ++$hardCount;

            foreach ($attachmentUids as $attachmentUid) {
                if ($this->messageRepository->isAttachmentReferenced($attachmentUid)) {
                    continue;
                }
                if ($this->attachmentService->deleteUploadIfUnused($attachmentUid)) {
                    $this->uploadRepository->forget($attachmentUid);
                    ++$deletedFiles;
                }
            }
        }

        $unsentRemoved = 0;
        foreach ($this->uploadRepository->findFileUidsRegisteredBefore($softCutoff) as $fileUid) {
            if (!$this->attachmentService->isInChatFolder($fileUid)) {
                $this->uploadRepository->forget($fileUid);

                continue;
            }
            if ($this->messageRepository->isAttachmentReferenced($fileUid)) {
                continue;
            }
            if ($this->attachmentService->deleteUploadIfUnused($fileUid)) {
                $this->uploadRepository->forget($fileUid);
                ++$unsentRemoved;
            }
        }

        if ($hardCount > 0 || $unsentRemoved > 0) {
            $this->logger->info('ChEddi: hard-deleted expired chat sessions', [
                'sessions' => $hardCount,
                'attachmentsRemoved' => $deletedFiles,
                'unsentUploadsRemoved' => $unsentRemoved,
            ]);
        }

        return ['softDeleted' => $softCount, 'hardDeleted' => $hardCount];
    }
}
