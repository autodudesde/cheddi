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
use AutoDudes\AiSuite\Service\UuidService;
use AutoDudes\AiSuiteMcp\Mcp\Service\NavigationTargetCollector;
use AutoDudes\Cheddi\Domain\Enum\Severity;
use AutoDudes\Cheddi\Domain\Model\ChatMessage;
use AutoDudes\Cheddi\Domain\Model\ChatSession;
use AutoDudes\Cheddi\Domain\Model\Dto\ChatToolContext;
use AutoDudes\Cheddi\Domain\Model\Dto\ChatTurnAnswer;
use AutoDudes\Cheddi\Domain\Model\Dto\TurnResult;
use AutoDudes\Cheddi\Domain\Repository\ChatMessageRepository;
use AutoDudes\Cheddi\Domain\Repository\ChatSessionRepository;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class ChatService
{
    public const TOOL_CAP_SOFT_WARNING = 20;
    public const TOOL_CAP_HARD_LIMIT = 40;

    public const SESSION_TITLE_MAX_LENGTH = 40;

    /** @var list<array{table: string, label: string, targets: list<array{label: string, url: string}>, omitted: int}> */
    private array $navigationTargets = [];

    public function __construct(
        protected readonly ChatSessionRepository $sessionRepository,
        protected readonly ChatMessageRepository $messageRepository,
        protected readonly ChatRequestService $chatRequestService,
        protected readonly ToolBridge $toolBridge,
        protected readonly ContextCollector $contextCollector,
        protected readonly BackendUserService $backendUserService,
        protected readonly UuidService $uuidService,
        protected readonly ChangeTracker $changeTracker,
        protected readonly AttachmentService $attachmentService,
        protected readonly ContextPrefillService $contextPrefillService,
        protected readonly ChatWriteCaptureService $writeCapture,
        protected readonly PendingPreviewService $pendingPreviewService,
        protected readonly NavigationTargetCollector $navigationTargetCollector,
        protected readonly LoggerInterface $logger,
    ) {}

    /**
     * @param list<int> $attachmentUids
     */
    public function startTurn(
        ?string $sessionUuid,
        string $userText,
        string $model,
        ServerRequestInterface $request,
        array $attachmentUids = [],
    ): TurnResult {
        $beUserUid = $this->resolveBeUserUid();
        if (0 === $beUserUid) {
            return TurnResult::error('', 'No backend user in context.');
        }

        $session = $this->resolveSession($sessionUuid, $beUserUid, $model);
        if (null === $session) {
            return TurnResult::error('', 'Chat session could not be resolved.');
        }

        $refs = [] === $attachmentUids ? [] : $this->attachmentService->normalizeRefs($attachmentUids);

        $this->messageRepository->append($session->uid, [
            'role' => ChatMessage::ROLE_USER,
            'content' => $this->attachmentService->mergeMarkersIntoContent($userText, $refs),
            'attachments' => [] === $refs ? '' : (string) json_encode($refs),
        ]);
        $this->sessionRepository->touchActivity($session->uid);

        if ('' === $session->title) {
            $title = $this->deriveTitleFromUserText($userText);
            if ('' !== $title) {
                $this->sessionRepository->updateTitle($session->uid, $title);
            }
        }

        return $this->runTurn($session, $request, true);
    }

    public function continueTurn(string $sessionUuid, ServerRequestInterface $request): TurnResult
    {
        $beUserUid = $this->resolveBeUserUid();
        if (0 === $beUserUid) {
            return TurnResult::error('', 'No backend user in context.');
        }
        $session = $this->sessionRepository->findByUuidForUser($sessionUuid, $beUserUid);
        if (null === $session) {
            return TurnResult::error($sessionUuid, 'Chat session not found.');
        }

        if (ChatMessage::ROLE_TOOL !== $this->messageRepository->findLastRole($session->uid)) {
            $this->logger->warning('ChEddi: continueTurn without an open turn', [
                'sessionUuid' => $session->sessionUuid,
            ]);

            return TurnResult::error($session->sessionUuid, 'There is no open turn to continue.', 'noOpenTurn');
        }

        $this->sessionRepository->touchActivity($session->uid);

        return $this->runTurn($session, $request);
    }

    /**
     * @param list<array{toolCallId: string, approved: bool}> $approvals
     */
    public function applyConfirmations(string $sessionUuid, array $approvals, ServerRequestInterface $request): TurnResult
    {
        $beUserUid = $this->resolveBeUserUid();
        if (0 === $beUserUid) {
            return TurnResult::error('', 'No backend user in context.');
        }
        $session = $this->sessionRepository->findByUuidForUser($sessionUuid, $beUserUid);
        if (null === $session) {
            return TurnResult::error($sessionUuid, 'Chat session not found.');
        }

        $context = new ChatToolContext($request, $session->sessionUuid, $session->model);

        $pendingCalls = $this->extractPendingToolCalls($session->uid);
        if ([] === $pendingCalls) {
            return TurnResult::error($session->sessionUuid, 'No pending tool calls to confirm.');
        }

        $approvalMap = [];
        foreach ($approvals as $approval) {
            $callId = $approval['toolCallId'] ?? null;
            if (is_string($callId) && '' !== $callId) {
                $approvalMap[$callId] = (bool) ($approval['approved'] ?? false);
            }
        }

        $this->writeCapture->begin();
        $applied = ['executed' => [], 'failed' => [], 'declined' => [], 'rejected' => []];

        try {
            foreach ($pendingCalls as $call) {
                $callId = (string) $call['id'];
                $approved = $approvalMap[$callId] ?? false;

                if (!$approved) {
                    $applied['declined'][] = (string) $call['name'];
                    $invalidNotes = $this->pendingPreviewService->invalidNotes((string) $call['name'], $call['arguments']);
                    $this->messageRepository->append($session->uid, [
                        'role' => ChatMessage::ROLE_TOOL,
                        'content' => [] === $invalidNotes
                            ? 'User declined this action.'
                            : sprintf(
                                'User declined this action. The payload was also invalid: %s. '
                                .'Correct it before offering the write again.',
                                implode(' | ', $invalidNotes),
                            ),
                        'tool_call_id' => $callId,
                        'tool_status' => ChatMessage::TOOL_STATUS_REJECTED,
                    ]);

                    continue;
                }

                $invalidReason = $this->pendingPreviewService->invalidReason((string) $call['name'], $call['arguments']);

                if (null !== $invalidReason) {
                    $applied['rejected'][] = (string) $call['name'];
                    $this->logger->warning('ChEddi: blocked a confirmed write with invalid records', [
                        'tool' => $call['name'],
                        'reason' => $invalidReason,
                    ]);
                    $this->messageRepository->append($session->uid, [
                        'role' => ChatMessage::ROLE_TOOL,
                        'content' => sprintf(
                            'Rejected before execution. Nothing was written. The payload is invalid: %s. Send a corrected writeRecords call.',
                            $invalidReason,
                        ),
                        'tool_call_id' => $callId,
                        'tool_status' => ChatMessage::TOOL_STATUS_FAILED,
                    ]);

                    continue;
                }

                $result = $this->toolBridge->execute((string) $call['name'], $call['arguments'], $context);
                $applied[$result['isError'] ? 'failed' : 'executed'][] = (string) $call['name'];
                $this->rememberNavigationTargets($result['structured'] ?? null);
                $this->messageRepository->append($session->uid, [
                    'role' => ChatMessage::ROLE_TOOL,
                    'content' => $result['content'],
                    'tool_call_id' => $callId,
                    'tool_status' => $result['isError']
                        ? ChatMessage::TOOL_STATUS_FAILED
                        : ChatMessage::TOOL_STATUS_DONE,
                ]);
            }
        } finally {
            foreach ($this->writeCapture->flush() as $change) {
                $this->changeTracker->track($session->uid, $change['table'], $change['uid'], $change['action']);
            }
            $this->writeCapture->end();
        }

        $this->logger->info('ChEddi confirmations applied', [
            'sessionUuid' => $session->sessionUuid,
            'toolsExecuted' => $applied['executed'],
            'toolsFailed' => $applied['failed'],
            'toolsDeclined' => $applied['declined'],
            'toolsRejected' => $applied['rejected'],
        ]);

        $this->sessionRepository->touchActivity($session->uid);

        return $this->runTurn($session, $request);
    }

    /**
     * @return list<array{uuid: string, title: string, model: string, lastActivity: int}>
     */
    public function listSessions(): array
    {
        $beUserUid = $this->resolveBeUserUid();
        if (0 === $beUserUid) {
            return [];
        }

        $sessions = $this->sessionRepository->findAllForUser($beUserUid);
        $result = [];
        foreach ($sessions as $session) {
            if ($session->deleted) {
                continue;
            }
            $result[] = [
                'uuid' => $session->sessionUuid,
                'title' => $session->title,
                'model' => $session->model,
                'lastActivity' => $session->lastActivity,
            ];
        }

        return $result;
    }

    /**
     * @return null|array{
     *     uuid: string,
     *     title: string,
     *     model: string,
     *     messages: list<array{role: string, content: string, toolCalls?: list<array<string, mixed>>, toolCallId?: string, toolStatus?: string}>,
     * }
     */
    public function loadSession(string $sessionUuid): ?array
    {
        $beUserUid = $this->resolveBeUserUid();
        if (0 === $beUserUid) {
            return null;
        }
        $session = $this->sessionRepository->findByUuidForUser($sessionUuid, $beUserUid);
        if (null === $session || $session->deleted) {
            return null;
        }

        $messages = array_map(
            $this->messageToApiShape(...),
            $this->messageRepository->findBySession($session->uid),
        );

        return [
            'uuid' => $session->sessionUuid,
            'title' => $session->title,
            'model' => $session->model,
            'messages' => $messages,
        ];
    }

    public function deleteSession(string $sessionUuid): bool
    {
        $beUserUid = $this->resolveBeUserUid();
        if (0 === $beUserUid) {
            return false;
        }
        $session = $this->sessionRepository->findByUuidForUser($sessionUuid, $beUserUid);
        if (null === $session) {
            return false;
        }
        $this->sessionRepository->softDelete($session->uid);

        return true;
    }

    private function deriveTitleFromUserText(string $text): string
    {
        $trimmed = trim($text);
        if ('' === $trimmed) {
            return '';
        }
        $collapsed = (string) preg_replace('/\s+/u', ' ', $trimmed);
        if (mb_strlen($collapsed) <= self::SESSION_TITLE_MAX_LENGTH) {
            return $collapsed;
        }

        return rtrim(mb_substr($collapsed, 0, self::SESSION_TITLE_MAX_LENGTH - 1)).'…';
    }

    /**
     * @return list<array{id: string, name: string, arguments: array<string, mixed>}>
     */
    private function extractPendingToolCalls(int $sessionUid): array
    {
        $messages = $this->messageRepository->findBySession($sessionUid);

        $lastAssistant = null;
        $answeredIds = [];
        for ($i = count($messages) - 1; $i >= 0; --$i) {
            $message = $messages[$i];
            if (ChatMessage::ROLE_TOOL === $message->role) {
                $answeredIds[$message->toolCallId] = true;

                continue;
            }
            if (ChatMessage::ROLE_ASSISTANT === $message->role && '' !== $message->toolCalls) {
                $lastAssistant = $message;

                break;
            }
        }
        if (null === $lastAssistant) {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($lastAssistant->toolCalls, true);
        if (!is_array($decoded)) {
            return [];
        }

        $pending = [];
        foreach ($decoded as $call) {
            if (!is_array($call)) {
                continue;
            }
            $id = (string) ($call['id'] ?? '');
            if ('' === $id || isset($answeredIds[$id])) {
                continue;
            }
            $arguments = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
            $pending[] = [
                'id' => $id,
                'name' => (string) ($call['name'] ?? ''),
                'arguments' => $arguments,
            ];
        }

        return $pending;
    }

    private function applyHistorySummaryIfPresent(ChatSession $session, ChatTurnAnswer $answer): void
    {
        if (null === $answer->historySummary) {
            return;
        }
        $replacedIds = $answer->historySummary['replacedMessageIds'] ?? [];
        $summaryContent = $answer->historySummary['summaryContent'] ?? '';
        if ([] === $replacedIds || '' === $summaryContent) {
            return;
        }
        $this->messageRepository->replaceWithSummary($session->uid, $replacedIds, $summaryContent);
        $this->logger->notice('Chat history summarised', [
            'sessionUuid' => $session->sessionUuid,
            'replacedCount' => count($replacedIds),
        ]);
    }

    private function runTurn(ChatSession $session, ServerRequestInterface $request, bool $prefillContext = false): TurnResult
    {
        $context = new ChatToolContext($request, $session->sessionUuid, $session->model);
        $history = $this->messageRepository->findBySession($session->uid);
        $messages = $this->reconcileDanglingToolCalls(
            array_map(fn (ChatMessage $m): array => $this->messageToApiShape($m, true), $history),
        );
        $tools = $this->toolBridge->getAvailableToolDefinitions();
        $systemContext = $this->contextCollector->build(
            $request,
            $this->toolBridge->webResearchAvailable(),
            $this->toolBridge->webPageReadingAvailable(),
        );
        if ($prefillContext) {
            $messages = [...$messages, ...$this->contextPrefillService->buildSyntheticMessages($context)];
        }

        $answer = $this->chatRequestService->executeTurn(
            $session->model,
            $messages,
            $tools,
            $systemContext,
        );

        if (ChatTurnAnswer::TYPE_ERROR === $answer->type) {
            $this->logger->warning('ChEddi turn failed', [
                'sessionUuid' => $session->sessionUuid,
                'historyMessages' => count($history),
                'model' => $session->model,
                'chatErrorCode' => $answer->chatErrorCode,
                'errorMessage' => $answer->errorMessage,
                'toolsOffered' => count($tools),
            ]);

            return TurnResult::error($session->sessionUuid, $answer->errorMessage ?? 'Unknown chat error.', $answer->chatErrorCode);
        }
        if ($answer->creditsExhausted) {
            return TurnResult::creditsExhausted(
                $session->sessionUuid,
                $this->buildUsage($answer),
                $answer->contextWindowTokens,
            );
        }

        $this->applyHistorySummaryIfPresent($session, $answer);

        $this->messageRepository->append($session->uid, [
            'role' => ChatMessage::ROLE_ASSISTANT,
            'content' => $answer->assistantText,
            'tool_calls' => $this->encodeToolCalls($answer->toolCalls),
            'provider_items' => $this->encodeProviderItems($answer->providerItems),
        ]);

        if ([] === $answer->toolCalls) {
            return $this->logTurnCompleted($session, $answer, $history, $tools, TurnResult::final(
                $session->sessionUuid,
                $answer->assistantText,
                [],
                $this->buildUsage($answer),
                $answer->contextWindowTokens,
                $answer->historySummary,
                [],
                $this->navigationTargets,
            ));
        }

        $existingToolCount = $this->countToolMessagesInCurrentSequence($history);
        $projectedToolCount = $existingToolCount + count($answer->toolCalls);

        if ($projectedToolCount > self::TOOL_CAP_HARD_LIMIT) {
            return $this->logTurnCompleted($session, $answer, $history, $tools, TurnResult::aborted(
                $session->sessionUuid,
                TurnResult::ABORT_REASON_TOOL_CAP_REACHED,
                $answer->assistantText,
                $this->buildUsage($answer),
                $answer->contextWindowTokens,
            ));
        }

        $notices = [];
        if ($existingToolCount < self::TOOL_CAP_SOFT_WARNING
            && $projectedToolCount >= self::TOOL_CAP_SOFT_WARNING) {
            $notices[] = [
                'key' => 'cheddi.notice.toolCapSoftWarning',
                'params' => [
                    'used' => $projectedToolCount,
                    'limit' => self::TOOL_CAP_HARD_LIMIT,
                ],
            ];
        }

        return $this->logTurnCompleted(
            $session,
            $answer,
            $history,
            $tools,
            $this->resolveToolCalls($session, $answer, $context, $notices),
        );
    }

    /**
     * @param list<ChatMessage>          $history
     * @param list<array<string, mixed>> $tools
     */
    private function logTurnCompleted(
        ChatSession $session,
        ChatTurnAnswer $answer,
        array $history,
        array $tools,
        TurnResult $result,
    ): TurnResult {
        $names = static fn (array $calls): array => array_map(
            static fn (array $call): string => (string) ($call['name'] ?? ''),
            $calls,
        );

        $context = [
            'sessionUuid' => $session->sessionUuid,
            'historyMessages' => count($history),
            'model' => $session->model,
            'status' => $result->status,
            'toolsOffered' => count($tools),
            'toolsRequested' => $names($answer->toolCalls),
            'toolsExecuted' => $names($result->toolCalls),
            'toolsPending' => $names($result->pending),
            'creditsCharged' => $answer->totalCredits,
            'remainingCredits' => $answer->remainingCredits,
        ];
        if (null !== $result->abortReason) {
            $context['abortReason'] = $result->abortReason;
        }

        $this->logger->info('ChEddi turn completed', $context);

        return $result;
    }

    /**
     * @param list<array{id: string, name: string, arguments: array<string, mixed>}> $toolCalls
     */
    private function encodeToolCalls(array $toolCalls): string
    {
        if ([] === $toolCalls) {
            return '';
        }

        try {
            return json_encode($toolCalls, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error('ChEddi: failed to encode assistant tool calls', [
                'exception' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * @param list<array<string, mixed>> $providerItems
     */
    private function encodeProviderItems(array $providerItems): string
    {
        if ([] === $providerItems) {
            return '';
        }

        try {
            return json_encode($providerItems, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error('ChEddi: failed to encode provider-native assistant items', [
                'exception' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * @param list<ChatMessage> $messages
     */
    private function countToolMessagesInCurrentSequence(array $messages): int
    {
        $count = 0;
        for ($i = count($messages) - 1; $i >= 0; --$i) {
            $message = $messages[$i];
            if (ChatMessage::ROLE_USER === $message->role) {
                break;
            }
            if (ChatMessage::ROLE_TOOL === $message->role) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param list<array{key: string, params?: array<string, int|string>}|string> $notices
     */
    private function resolveToolCalls(ChatSession $session, ChatTurnAnswer $answer, ChatToolContext $context, array $notices = []): TurnResult
    {
        $autoExecuted = [];
        $pending = [];
        $sources = [];
        foreach ($answer->toolCalls as $call) {
            $severity = $this->toolBridge->resolvePolicy($call['name'], $call['arguments']);
            if (Severity::ReadOnly === $severity) {
                $result = $this->toolBridge->execute($call['name'], $call['arguments'], $context);
                $structured = $result['structured'] ?? null;
                $this->rememberNavigationTargets($structured);
                if (is_array($structured) && is_array($structured['webSearch']['sources'] ?? null)) {
                    foreach ($structured['webSearch']['sources'] as $source) {
                        $url = is_array($source) ? trim((string) ($source['url'] ?? '')) : '';
                        if ('' !== $url) {
                            $sources[$url] = [
                                'title' => (string) ($source['title'] ?? $url),
                                'url' => $url,
                                'snippet' => (string) ($source['snippet'] ?? ''),
                                'text' => (string) ($source['text'] ?? ''),
                            ];
                        }
                    }
                }
                $this->messageRepository->append($session->uid, [
                    'role' => ChatMessage::ROLE_TOOL,
                    'content' => $result['content'],
                    'tool_call_id' => $call['id'],
                    'tool_status' => $result['isError']
                        ? ChatMessage::TOOL_STATUS_FAILED
                        : ChatMessage::TOOL_STATUS_DONE,
                ]);
                $autoExecuted[] = [
                    'id' => $call['id'],
                    'name' => $call['name'],
                    'arguments' => $call['arguments'],
                    'status' => $result['isError']
                        ? ChatMessage::TOOL_STATUS_FAILED
                        : ChatMessage::TOOL_STATUS_DONE,
                ];

                continue;
            }
            $pending[] = [
                'id' => $call['id'],
                'name' => $call['name'],
                'arguments' => $call['arguments'],
                'severity' => $severity->value,
                'preview' => $this->pendingPreviewService->build($call['name'], $call['arguments']),
            ];
        }

        if ([] !== $pending) {
            return TurnResult::needsConfirm(
                $session->sessionUuid,
                $answer->assistantText,
                $pending,
                $this->buildUsage($answer),
                $answer->contextWindowTokens,
                $notices,
                $autoExecuted,
            );
        }

        return TurnResult::continuing(
            $session->sessionUuid,
            $answer->assistantText,
            $autoExecuted,
            $this->buildUsage($answer),
            $answer->contextWindowTokens,
            $notices,
            array_values($sources),
            $this->navigationTargets,
        );
    }

    private function rememberNavigationTargets(mixed $structured): void
    {
        if (!is_array($structured)) {
            return;
        }
        $this->navigationTargets = $this->navigationTargetCollector->merge(
            $this->navigationTargets,
            $this->navigationTargetCollector->collect($structured),
        );
    }

    private function resolveSession(?string $sessionUuid, int $beUserUid, string $model): ?ChatSession
    {
        if (null !== $sessionUuid && '' !== $sessionUuid) {
            $existing = $this->sessionRepository->findByUuidForUser($sessionUuid, $beUserUid);
            if (null !== $existing) {
                if ('' !== $model && $model !== $existing->model) {
                    $this->logger->warning('ChEddi: requested model ignored, the conversation is pinned to its own model', [
                        'sessionModel' => $existing->model,
                        'requestedModel' => $model,
                    ]);
                }

                return $existing;
            }
        }

        if ('' === $model) {
            $this->logger->warning('Cannot create chat session without model identifier');

            return null;
        }

        $newUuid = null !== $sessionUuid && '' !== $sessionUuid
            ? $sessionUuid
            : $this->uuidService->generateUuid();
        $sessionId = $this->sessionRepository->create($newUuid, $beUserUid, $model);

        return new ChatSession(
            uid: $sessionId,
            sessionUuid: $newUuid,
            beUser: $beUserUid,
            model: $model,
        );
    }

    private function resolveBeUserUid(): int
    {
        $beUser = $this->backendUserService->getBackendUser();
        if (null === $beUser) {
            return 0;
        }

        return (int) ($beUser->user['uid'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUsage(ChatTurnAnswer $answer): array
    {
        return [
            'inputTokens' => $answer->inputTokens,
            'outputTokens' => $answer->outputTokens,
            'totalCredits' => $answer->totalCredits,
            'remainingCredits' => $answer->remainingCredits,
            'lowBalance' => $answer->lowBalance,
        ];
    }

    /**
     * @param list<array{role: string, content: string, toolCalls?: list<array<string, mixed>>, toolCallId?: string, toolStatus?: string}> $messages
     *
     * @return list<array{role: string, content: string, toolCalls?: list<array<string, mixed>>, toolCallId?: string, toolStatus?: string}>
     */
    private function reconcileDanglingToolCalls(array $messages): array
    {
        $reconciled = [];
        $count = count($messages);
        $i = 0;

        while ($i < $count) {
            $message = $messages[$i];
            $reconciled[] = $message;

            $toolCalls = $message['toolCalls'] ?? null;
            if (ChatMessage::ROLE_ASSISTANT !== ($message['role'] ?? '') || !is_array($toolCalls) || [] === $toolCalls) {
                ++$i;

                continue;
            }

            $requiredIds = [];
            foreach ($toolCalls as $call) {
                if (is_array($call) && isset($call['id']) && is_string($call['id']) && '' !== $call['id']) {
                    $requiredIds[$call['id']] = true;
                }
            }

            $answered = [];
            $j = $i + 1;
            while ($j < $count && ChatMessage::ROLE_TOOL === ($messages[$j]['role'] ?? '')) {
                $reconciled[] = $messages[$j];
                $answeredId = (string) ($messages[$j]['toolCallId'] ?? '');
                if ('' !== $answeredId) {
                    $answered[$answeredId] = true;
                }
                ++$j;
            }

            foreach (array_keys($requiredIds) as $id) {
                if (!isset($answered[$id])) {
                    $reconciled[] = [
                        'role' => ChatMessage::ROLE_TOOL,
                        'content' => 'Not executed — the user sent a new message before confirming this action.',
                        'toolCallId' => $id,
                        'toolStatus' => ChatMessage::TOOL_STATUS_REJECTED,
                    ];
                }
            }

            $i = $j;
        }

        return $reconciled;
    }

    /**
     * @return array{role: string, content: string, toolCalls?: list<array<string, mixed>>, toolCallId?: string, toolStatus?: string, providerItems?: string}
     */
    private function messageToApiShape(ChatMessage $message, bool $withProviderItems = false): array
    {
        $shape = [
            'role' => $message->role,
            'content' => $message->content,
        ];
        if ('' !== $message->toolCalls) {
            /** @var mixed $decoded */
            $decoded = json_decode($message->toolCalls, true);
            if (is_array($decoded)) {
                /** @var list<array<string, mixed>> $filtered */
                $filtered = array_values(array_filter($decoded, 'is_array'));
                $shape['toolCalls'] = $filtered;
            }
        }
        if ('' !== $message->toolCallId) {
            $shape['toolCallId'] = $message->toolCallId;
        }
        if ('' !== $message->toolStatus) {
            $shape['toolStatus'] = $message->toolStatus;
        }
        if ($withProviderItems && '' !== $message->providerItems) {
            $shape['providerItems'] = $message->providerItems;
        }

        return $shape;
    }
}
