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

namespace AutoDudes\Cheddi\StatusReport;

use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\Cheddi\Command\AutoDeleteChatSessionsCommand;
use AutoDudes\Cheddi\Command\CollectOpenItemsCommand;
use AutoDudes\Cheddi\Domain\Repository\BackendGroupRepository;
use AutoDudes\Cheddi\Domain\Repository\OpenItemRuleRepository;
use AutoDudes\Cheddi\Domain\Repository\SchedulerTaskRepository;
use AutoDudes\Cheddi\Service\Chat\AttachmentService;
use AutoDudes\Cheddi\Service\Chat\ChatRequestService;
use AutoDudes\Cheddi\Service\Chat\ChatSettingsService;
use AutoDudes\Cheddi\Service\Chat\DocumentExtractorService;
use AutoDudes\Cheddi\Service\Chat\OpenItem\OpenItemRuleValidator;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Reports\StatusProviderInterface;

class CheddiEnvironmentStatus implements StatusProviderInterface
{
    public function __construct(
        private readonly DocumentExtractorService $documentExtractor,
        private readonly AttachmentService $attachmentService,
        private readonly ChatSettingsService $chatSettings,
        private readonly BackendUserService $backendUserService,
        private readonly SchedulerTaskRepository $schedulerTaskRepository,
        private readonly BackendGroupRepository $backendGroupRepository,
        private readonly OpenItemRuleRepository $openItemRules,
        private readonly OpenItemRuleValidator $openItemRuleValidator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return list<Status>
     */
    public function getStatus(): array
    {
        return [
            $this->documentReadingStatus(),
            $this->executionTimeStatus(),
            $this->uploadFolderStatus(),
            $this->cleanupTaskStatus(),
            $this->openItemsTaskStatus(),
            $this->openItemRulesStatus(),
        ];
    }

    public function getLabel(): string
    {
        return 'ChEddi Environment';
    }

    protected function maxExecutionTime(): int
    {
        return (int) ini_get('max_execution_time');
    }

    private function documentReadingStatus(): Status
    {
        $unavailable = $this->documentExtractor->unavailableFormats();
        if ([] === $unavailable) {
            return new Status(
                'ChEddi Attachments',
                'All document types readable',
                'PDF, Word and spreadsheet attachments can be read.',
                ContextualFeedbackSeverity::OK,
            );
        }

        $reasons = [];
        foreach ($unavailable as $format => $reason) {
            $reasons[] = sprintf('%s: %s', $format, $reason);
        }

        return new Status(
            'ChEddi Attachments',
            sprintf('%d document type(s) not readable', count($unavailable)),
            sprintf(
                'Editors can attach these files, but ChEddi cannot read their text (%s). '
                .'Classic mode installations ship the libraries in Resources/Private/PHP of cheddi; the PHP extensions have to be installed on the server.',
                implode('; ', $reasons),
            ),
            ContextualFeedbackSeverity::WARNING,
        );
    }

    private function uploadFolderStatus(): Status
    {
        $configured = $this->chatSettings->getUploadFolder();
        $backendUser = $this->backendUserService->getBackendUser();
        $folder = null === $backendUser ? null : $this->attachmentService->baseUploadFolder($backendUser);

        $findings = [];
        $severity = ContextualFeedbackSeverity::OK;
        if (null === $folder) {
            $findings[] = '' === $configured
                ? 'Your backend user has no default upload folder, so ChEddi refuses attachments for you. Editors with one are not affected.'
                : sprintf('The chat upload folder "%s" (chatUploadFolder) does not exist, so ChEddi refuses every attachment that would go there. Create it or correct the setting.', $configured);
            $severity = '' === $configured ? ContextualFeedbackSeverity::NOTICE : ContextualFeedbackSeverity::ERROR;
        } elseif ($folder->getStorage()->isPublic()) {
            $findings[] = $this->publicFolderFinding($folder->getCombinedIdentifier(), '' === $configured
                ? 'This is the default upload folder of your user; other editors may use other folders.'
                : 'This is the folder of the extension configuration.');
            $severity = ContextualFeedbackSeverity::WARNING;
        }

        foreach ($this->backendGroupRepository->findWithChatUploadFolder() as $group) {
            $groupFolder = $this->attachmentService->resolveConfiguredFolder($group['folder']);
            if (null === $groupFolder) {
                $findings[] = sprintf('Backend group "%s" (uid %d) sets the upload folder "%s", which does not exist; its members cannot attach files.', $group['title'], $group['uid'], $group['folder']);
                $severity = ContextualFeedbackSeverity::ERROR;
            } elseif ($groupFolder->getStorage()->isPublic()) {
                $findings[] = $this->publicFolderFinding($groupFolder->getCombinedIdentifier(), sprintf('It is set on backend group "%s" (uid %d).', $group['title'], $group['uid']));
                if (ContextualFeedbackSeverity::ERROR !== $severity) {
                    $severity = ContextualFeedbackSeverity::WARNING;
                }
            }
        }

        if ([] === $findings) {
            return new Status(
                'ChEddi Upload Folder',
                'Not public',
                'Chat attachments are stored in a non-public storage and are only delivered to backend users with access to it.',
                ContextualFeedbackSeverity::OK,
            );
        }

        return new Status(
            'ChEddi Upload Folder',
            ContextualFeedbackSeverity::ERROR === $severity ? 'Folder missing' : (ContextualFeedbackSeverity::WARNING === $severity ? 'Publicly reachable' : 'No folder for your user'),
            implode(' ', $findings),
            $severity,
        );
    }

    private function publicFolderFinding(string $folder, string $origin): string
    {
        return sprintf(
            'Chat attachments are stored in %s%s/, a folder of a public storage. %s Anyone who knows a file\'s URL can download it without logging in; %s '
            .'Set chatUploadFolder to a folder of a non-public storage to keep attachments private.',
            $folder,
            AttachmentService::UPLOAD_FOLDER_NAME,
            $origin,
            $this->chatSettings->usesRandomUploadNames()
                ? 'the random prefix in every file name makes the URL hard to guess, not private.'
                : 'random file names are switched off (chatUploadRandomNames), so a URL can be guessed from the original file name.',
        );
    }

    private function cleanupTaskStatus(): Status
    {
        try {
            $scheduled = $this->schedulerTaskRepository->hasActiveCommandTask(AutoDeleteChatSessionsCommand::NAME);
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: could not look up the scheduler task for the session cleanup', [
                'exception' => $e->getMessage(),
            ]);

            return new Status(
                'ChEddi Session Cleanup',
                'Not determinable',
                sprintf('The scheduler tasks could not be read. Make sure a task runs the command %s regularly.', AutoDeleteChatSessionsCommand::NAME),
                ContextualFeedbackSeverity::NOTICE,
            );
        }

        if (!$scheduled) {
            return new Status(
                'ChEddi Session Cleanup',
                'No active scheduler task',
                sprintf(
                    'Editors are told that inactive chats are deleted after %d days, but no active scheduler task runs the command %s. '
                    .'Until one does, chats, their attachments and uploads that were never sent are kept indefinitely. '
                    .'Add an "Execute console commands" task for it in the Scheduler module, daily is enough.',
                    $this->chatSettings->getSessionLifetimeDays(),
                    AutoDeleteChatSessionsCommand::NAME,
                ),
                ContextualFeedbackSeverity::WARNING,
            );
        }

        return new Status(
            'ChEddi Session Cleanup',
            'Scheduler task active',
            sprintf('An active scheduler task runs %s. Whether the scheduler cron job itself runs is shown under "Scheduler".', AutoDeleteChatSessionsCommand::NAME),
            ContextualFeedbackSeverity::OK,
        );
    }

    private function openItemRulesStatus(): Status
    {
        $problems = $this->openItemRules->findRuleProblems();
        if ([] === $problems) {
            return new Status('ChEddi Open Item Checks', 'No problems', 'No check reported a problem in its last run.', ContextualFeedbackSeverity::OK);
        }

        return new Status(
            'ChEddi Open Item Checks',
            sprintf('%d with a problem', count($problems)),
            implode(' ', array_map(
                fn (array $problem): string => sprintf('"%s" (uid %d): %s', $problem['title'], $problem['uid'], $this->openItemRuleValidator->problemLabel($problem['problem'])),
                $problems,
            )),
            ContextualFeedbackSeverity::WARNING,
        );
    }

    private function openItemsTaskStatus(): Status
    {
        $title = 'ChEddi Open Items';
        if ([] === $this->openItemRules->findActive()) {
            return new Status($title, 'Switched off', 'No check is switched on.', ContextualFeedbackSeverity::OK);
        }

        try {
            $scheduled = $this->schedulerTaskRepository->hasActiveCommandTask(CollectOpenItemsCommand::NAME);
        } catch (\Throwable $e) {
            $this->logger->warning('ChEddi: could not look up the scheduler task for the open items', [
                'exception' => $e->getMessage(),
            ]);

            return new Status(
                $title,
                'Not determinable',
                sprintf('The scheduler tasks could not be read. Make sure a task runs the command %s regularly.', CollectOpenItemsCommand::NAME),
                ContextualFeedbackSeverity::NOTICE,
            );
        }

        if (!$scheduled) {
            return new Status(
                $title,
                'No active scheduler task',
                sprintf(
                    'No active scheduler task runs the command %s, so editors see no open items or outdated ones. '
                    .'Add an "Execute console commands" task for it in the Scheduler module, daily is enough.',
                    CollectOpenItemsCommand::NAME,
                ),
                ContextualFeedbackSeverity::WARNING,
            );
        }

        return new Status(
            $title,
            'Scheduler task active',
            sprintf('An active scheduler task runs %s.', CollectOpenItemsCommand::NAME),
            ContextualFeedbackSeverity::OK,
        );
    }

    private function executionTimeStatus(): Status
    {
        $limit = $this->maxExecutionTime();
        $required = ChatRequestService::REQUEST_TIMEOUT_SECONDS;

        if ($limit > 0 && $limit < $required) {
            return new Status(
                'ChEddi Request Duration',
                sprintf('max_execution_time is %d s', $limit),
                sprintf(
                    'A chat turn may wait up to %d seconds for the AI Suite Server. PHP stops the request after %d seconds, '
                    .'so long turns end in an error. Raise max_execution_time and the matching web server or proxy timeout to at least %d seconds.',
                    $required,
                    $limit,
                    $required,
                ),
                ContextualFeedbackSeverity::WARNING,
            );
        }

        return new Status(
            'ChEddi Request Duration',
            $limit > 0 ? sprintf('max_execution_time is %d s', $limit) : 'max_execution_time is unlimited',
            sprintf('PHP allows a chat turn the %d seconds it may need. Web server and proxy timeouts are not visible from here.', $required),
            ContextualFeedbackSeverity::OK,
        );
    }
}
