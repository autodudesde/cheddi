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

use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuiteMcp\Mcp\Service\WorkspaceRecordService;
use AutoDudes\Cheddi\Domain\Repository\ChatChangeRepository;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;

class ChangeTracker
{
    public function __construct(
        private readonly ChatChangeRepository $changeRepository,
        private readonly ChatOrientationService $orientationService,
        private readonly BackendUserService $backendUserService,
        private readonly Context $context,
        private readonly WorkspaceRecordService $workspaceRecordService,
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly LoggerInterface $logger,
    ) {}

    public function track(int $sessionUid, string $table, int $uid, string $action): void
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (null === $backendUser) {
            return;
        }

        try {
            $workspace = $this->orientationService->resolveWorkspaceId($backendUser);
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: could not resolve the workspace for change tracking', [
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        if ($workspace <= 0) {
            return;
        }

        $liveUid = $this->resolveLiveUid($table, $uid);

        if ($this->changeRepository->hasChange($sessionUid, $table, $liveUid)) {
            return;
        }

        try {
            $this->changeRepository->addChange(
                $sessionUid,
                $table,
                $liveUid,
                $this->resolveWorkspaceRecordUid($workspace, $table, $liveUid),
                $workspace,
                $this->resolvePageId($table, $liveUid),
                $action,
                $this->currentTimestamp(),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: could not record a workspace change', [
                'table' => $table,
                'uid' => $uid,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    protected function resolveLiveUid(string $table, int $uid): int
    {
        return $this->workspaceRecordService->resolveLiveUid($table, $uid);
    }

    protected function resolveWorkspaceRecordUid(int $workspace, string $table, int $liveUid): int
    {
        if (!$this->tcaCompatibilityService->isWorkspaceAware($table)) {
            return $liveUid;
        }

        try {
            $versioned = BackendUtility::getWorkspaceVersionOfRecord($workspace, $table, $liveUid, 'uid');
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: workspace version lookup failed', [
                'table' => $table,
                'uid' => $liveUid,
                'exception' => $e->getMessage(),
            ]);

            return $liveUid;
        }

        return is_array($versioned) && isset($versioned['uid']) ? (int) $versioned['uid'] : $liveUid;
    }

    protected function resolvePageId(string $table, int $uid): int
    {
        if ('pages' === $table) {
            return $uid;
        }

        $record = BackendUtility::getRecord($table, $uid, 'pid');

        return is_array($record) ? (int) ($record['pid'] ?? 0) : 0;
    }

    protected function currentTimestamp(): int
    {
        return (int) $this->context->getPropertyFromAspect('date', 'timestamp', 0);
    }
}
