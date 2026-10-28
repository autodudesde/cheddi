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

namespace AutoDudes\Cheddi\Controller;

use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuiteMcp\Domain\Model\Dto\RateLimitPolicy;
use AutoDudes\AiSuiteMcp\Mcp\Service\RateLimiterService;
use AutoDudes\Cheddi\Domain\Repository\ChatUploadRepository;
use AutoDudes\Cheddi\Enumeration\CheddiPermissionEnumeration;
use AutoDudes\Cheddi\Service\Chat\AttachmentService;
use AutoDudes\Cheddi\Service\Chat\ChatSettingsService;
use AutoDudes\Cheddi\Service\Chat\UploadedFileWriter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Resource\Folder;

#[AsController]
final class AttachmentController
{
    private const UPLOADS_PER_WINDOW = 30;
    private const UPLOAD_WINDOW_SECONDS = 600;

    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly UploadedFileWriter $uploadedFileWriter,
        private readonly BackendUserService $backendUserService,
        private readonly RateLimiterService $rateLimiter,
        private readonly ChatUploadRepository $uploadRepository,
        private readonly ChatSettingsService $chatSettings,
        private readonly LoggerInterface $logger,
    ) {}

    public function uploadAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->guardPermission();
        if (null !== $denied) {
            return $denied;
        }

        $limited = $this->guardRateLimit();
        if (null !== $limited) {
            return $limited;
        }

        $uploaded = $request->getUploadedFiles()['file'] ?? null;
        if (!$uploaded instanceof UploadedFileInterface || UPLOAD_ERR_OK !== $uploaded->getError()) {
            return $this->error('No file was uploaded.', 400);
        }

        $name = (string) $uploaded->getClientFilename();
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!$this->attachmentService->isSupportedExtension($extension, $this->backendUserService->checkPermissions(CheddiPermissionEnumeration::MEDIA_UPLOAD))) {
            return $this->error('unsupported', 415);
        }

        $size = $uploaded->getSize();
        if (null === $size || $size > (int) $this->attachmentService->maxBytesFor($extension)) {
            return $this->error('oversize', 413);
        }

        $folder = $this->resolveUploadFolder();
        if (null === $folder) {
            return $this->error('No writable upload folder is available for this user.', 403);
        }

        try {
            $file = $this->uploadedFileWriter->write($uploaded, $folder, $this->chatSettings->usesRandomUploadNames());
            if (null === $file) {
                return $this->error('The file could not be stored.', 500);
            }
            $this->uploadRepository->register($file->getUid(), $this->beUserUid(), time());
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: attachment upload failed', [
                'name' => $name,
                'exception' => $e->getMessage(),
            ]);

            return $this->error('The file could not be stored.', 500);
        }

        return new JsonResponse(['attachment' => $this->attachmentService->preview($file->getUid())]);
    }

    private function resolveUploadFolder(): ?Folder
    {
        $backendUser = $this->backendUserService->getBackendUser();

        return null === $backendUser ? null : $this->attachmentService->resolveUploadFolder($backendUser);
    }

    private function guardRateLimit(): ?ResponseInterface
    {
        $identifier = 'cheddi_upload_'.$this->beUserUid();

        try {
            $this->rateLimiter->checkAndIncrement(
                $identifier,
                new RateLimitPolicy(self::UPLOADS_PER_WINDOW, self::UPLOAD_WINDOW_SECONDS, self::UPLOAD_WINDOW_SECONDS),
            );
        } catch (\RuntimeException $e) {
            $this->logger->warning('ChEddi: attachment upload rate limit exceeded', [
                'identifier' => $identifier,
                'reason' => $e->getMessage(),
            ]);

            return $this->error('rateLimited', 429);
        }

        return null;
    }

    private function beUserUid(): int
    {
        return (int) ($this->backendUserService->getBackendUser()?->user['uid'] ?? 0);
    }

    private function guardPermission(): ?ResponseInterface
    {
        return $this->backendUserService->checkPermissions(CheddiPermissionEnumeration::INTERFACE)
            ? null
            : $this->error('ChEddi is not enabled for this backend user.', 403);
    }

    private function error(string $message, int $status): ResponseInterface
    {
        return new JsonResponse(['error' => ['message' => $message]], $status);
    }
}
