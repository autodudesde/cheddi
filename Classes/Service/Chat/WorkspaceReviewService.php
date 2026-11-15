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
use AutoDudes\AiSuite\Service\WorkspaceContextService;
use AutoDudes\AiSuiteMcp\Mcp\Service\DataHandlerErrorFormatter;
use AutoDudes\AiSuiteMcp\Mcp\Service\RecordLanguageLabelService;
use AutoDudes\AiSuiteMcp\Mcp\Service\WorkspaceComparisonService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\WriteContainment;
use AutoDudes\Cheddi\Domain\Repository\ChatChangeRepository;
use AutoDudes\Cheddi\Exception\WorkspaceAccessException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class WorkspaceReviewService
{
    public const STATUS_ADDED = 'added';
    public const STATUS_CHANGED = 'changed';
    public const STATUS_REMOVED = 'removed';
    public const STATUS_LIVE = 'live';

    public function __construct(
        private readonly ChatChangeRepository $changeRepository,
        private readonly WorkspaceComparisonService $comparisonService,
        private readonly BackendUserService $backendUserService,
        private readonly WorkspaceContextService $workspaceContextService,
        private readonly LoggerInterface $logger,
        private readonly DataHandlerErrorFormatter $dataHandlerErrorFormatter,
        private readonly RecordLanguageLabelService $recordLanguageLabelService,
        private readonly WorkspaceChangeLabeler $labeler,
    ) {}

    /**
     * @return list<array{uid: int, table: string, recordUid: int, workspaceRecordUid: int, pageId: int, action: string, label: string, language: string, status: string, changedFields: list<string>, changedFieldLabels: list<string>}>
     */
    public function describeChanges(int $sessionUid): array
    {
        $described = [];

        foreach ($this->changeRepository->findBySession($sessionUid) as $change) {
            $table = (string) $change['tablename'];
            $workspaceRecordUid = (int) $change['workspace_record_uid'];
            $workspace = (int) $change['workspace'];

            $label = '';
            $status = (string) $change['action'];
            $changedFields = [];

            if ($this->isImmediate($change)) {
                $status = self::STATUS_LIVE;
                $label = sprintf('#%d', (int) $change['record_uid']);
            } elseif ($workspaceRecordUid > 0) {
                try {
                    $diff = $this->comparisonService->compareSingle($table, $workspaceRecordUid, $workspace);
                    $label = $diff['label'];
                    $status = $diff['status'];
                    $changedFields = array_keys($diff['changes']);
                } catch (\RuntimeException $e) {
                    // The draft is gone (published or discarded elsewhere); show the bare row.
                    $this->logger->info('ChEddi: could not diff a tracked change', [
                        'table' => $table,
                        'uid' => $workspaceRecordUid,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }

            $changedFields = array_values(array_map('strval', $changedFields));
            $described[] = [
                'uid' => (int) $change['uid'],
                'table' => $table,
                'recordUid' => (int) $change['record_uid'],
                'workspaceRecordUid' => $workspaceRecordUid,
                'pageId' => (int) $change['page_id'],
                'action' => (string) $change['action'],
                'label' => $this->labeler->recordLabel($table, $workspaceRecordUid, (int) $change['record_uid'], $label),
                'language' => $this->recordLanguageLabelService->titleOf($table, $workspaceRecordUid > 0 ? $workspaceRecordUid : (int) $change['record_uid']),
                'status' => $this->normaliseStatus($status),
                'changedFields' => $changedFields,
                'changedFieldLabels' => $this->labeler->fieldLabels($table, $changedFields),
            ];
        }

        return $described;
    }

    /**
     * @param list<int> $changeUids
     *
     * @return array{applied: list<int>, errors: list<string>}
     *
     * @throws WorkspaceAccessException
     */
    public function apply(int $sessionUid, array $changeUids, bool $publish): array
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (null === $backendUser) {
            throw new WorkspaceAccessException('No backend user in context.');
        }

        $selected = [];
        foreach ($this->changeRepository->findBySession($sessionUid) as $change) {
            if (in_array((int) $change['uid'], $changeUids, true) && !$this->isImmediate($change)) {
                $selected[] = $change;
            }
        }

        if ([] === $selected) {
            return ['applied' => [], 'errors' => []];
        }

        foreach ($selected as $change) {
            $workspace = (int) $change['workspace'];
            if ($workspace <= 0 || false === $backendUser->checkWorkspace($workspace)) {
                throw new WorkspaceAccessException('No access to the workspace of this change.');
            }
        }

        $applied = [];
        $errors = [];

        foreach ($selected as $change) {
            $table = (string) $change['tablename'];
            $workspaceRecordUid = (int) $change['workspace_record_uid'];
            $workspace = (int) $change['workspace'];

            if ($workspaceRecordUid <= 0) {
                $this->changeRepository->removeByUid((int) $change['uid']);
                $applied[] = (int) $change['uid'];

                continue;
            }

            $error = $this->workspaceContextService->withWorkspace(
                $backendUser,
                $workspace,
                fn (): ?string => $publish
                    ? $this->publishRecord($table, $workspaceRecordUid)
                    : $this->discardRecord($table, $workspaceRecordUid),
            );

            if (null !== $error) {
                $errors[] = $error;

                continue;
            }

            $this->changeRepository->removeByRecord($table, (int) $change['record_uid'], $workspace);
            $applied[] = (int) $change['uid'];
        }

        return ['applied' => $applied, 'errors' => $errors];
    }

    protected function publishRecord(string $table, int $workspaceRecordUid): ?string
    {
        $liveUid = BackendUtility::getLiveVersionIdOfRecord($table, $workspaceRecordUid) ?? $workspaceRecordUid;

        return $this->runCommand('publish', $table, $liveUid, [
            $table => [
                $liveUid => ['version' => ['action' => 'publish', 'swapWith' => $workspaceRecordUid]],
            ],
        ]);
    }

    protected function discardRecord(string $table, int $workspaceRecordUid): ?string
    {
        return $this->runCommand('discard', $table, $workspaceRecordUid, [
            $table => [
                $workspaceRecordUid => ['discard' => true],
            ],
        ]);
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $cmd
     */
    private function runCommand(string $operation, string $table, int $uid, array $cmd): ?string
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmd);
        $dataHandler->process_cmdmap();

        if ([] === $dataHandler->errorLog) {
            return null;
        }

        $message = $this->dataHandlerErrorFormatter->toException($operation, $table, $uid, $dataHandler->errorLog)->getMessage();
        $this->logger->warning('ChEddi: workspace review command failed', ['error' => $message]);

        return $message;
    }

    /**
     * @param array<string, mixed> $change
     */
    private function isImmediate(array $change): bool
    {
        return WriteContainment::IMMEDIATE === WriteContainment::of((string) $change['action']);
    }

    private function normaliseStatus(string $status): string
    {
        return match ($status) {
            self::STATUS_LIVE => self::STATUS_LIVE,
            'added', 'create' => self::STATUS_ADDED,
            'removed', 'delete' => self::STATUS_REMOVED,
            default => self::STATUS_CHANGED,
        };
    }
}
