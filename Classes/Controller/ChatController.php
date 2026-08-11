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

use AutoDudes\AiSuite\Factory\SettingsFactory;
use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuiteMcp\Mcp\Service\RateLimiterService;
use AutoDudes\Cheddi\Domain\Model\Dto\TurnResult;
use AutoDudes\Cheddi\Service\Chat\AttachmentService;
use AutoDudes\Cheddi\Service\Chat\ChatCatalogService;
use AutoDudes\Cheddi\Service\Chat\ChatHelpService;
use AutoDudes\Cheddi\Service\Chat\ChatService;
use AutoDudes\Cheddi\Service\Chat\GdprModelPolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;

#[AsController]
final class ChatController
{
    private const FEATURE_FLAG = 'tx_aisuite_features:enable_cheddi_interface';

    public function __construct(
        private readonly ChatService $chatService,
        private readonly ChatCatalogService $chatCatalogService,
        private readonly ChatHelpService $chatHelpService,
        private readonly BackendUserService $backendUserService,
        private readonly SettingsFactory $settingsFactory,
        private readonly GdprModelPolicy $gdprModelPolicy,
        private readonly AttachmentService $attachmentService,
        private readonly RateLimiterService $rateLimiter,
        private readonly LoggerInterface $logger,
    ) {}

    public function startTurnAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->guardPermission();
        if (null !== $denied) {
            return $denied;
        }

        $params = $this->parsedBody($request);
        $limited = $this->guardRateLimit($this->stringParam($params, 'sessionUuid'));
        if (null !== $limited) {
            return $limited;
        }

        $text = $this->stringParam($params, 'text');
        if ('' === $text) {
            return $this->badRequest('Missing or empty `text` field.');
        }
        $model = $this->stringParam($params, 'model');
        if ($this->gdprModelPolicy->isForced($this->settingsFactory->mergeExtConfAndUserGroupSettings())) {
            if ('' === $model) {
                $model = $this->gdprModelPolicy->defaultModel();
            } elseif (!$this->gdprModelPolicy->isCompliant($model)) {
                return new JsonResponse(
                    ['error' => ['message' => 'GDPR mode: only data-protection-compliant models may be used.', 'chatErrorCode' => 'gdprModelBlocked']],
                    403,
                );
            }
        }
        $modelDenied = $this->guardModelPermission($model);
        if (null !== $modelDenied) {
            return $modelDenied;
        }
        $sessionUuid = $this->nullableStringParam($params, 'sessionUuid');

        $attachmentUids = $this->attachmentService->parseClientPayload($params['attachments'] ?? null);

        $result = $this->chatService->startTurn($sessionUuid, $text, $model, $request, $attachmentUids);

        return $this->turnResultToResponse($result);
    }

    public function continueTurnAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->guardPermission();
        if (null !== $denied) {
            return $denied;
        }

        $params = $this->parsedBody($request);
        $sessionUuid = $this->stringParam($params, 'sessionUuid');
        if ('' === $sessionUuid) {
            return $this->badRequest('Missing `sessionUuid` for continueTurn.');
        }
        $limited = $this->guardRateLimit($sessionUuid);
        if (null !== $limited) {
            return $limited;
        }

        $result = $this->chatService->continueTurn($sessionUuid, $request);

        return $this->turnResultToResponse($result);
    }

    public function listSessionsAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->guardPermission();
        if (null !== $denied) {
            return $denied;
        }

        return new JsonResponse(['sessions' => $this->chatService->listSessions()]);
    }

    public function loadSessionAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->guardPermission();
        if (null !== $denied) {
            return $denied;
        }

        $params = $this->parsedBody($request);
        $sessionUuid = $this->stringParam($params, 'sessionUuid');
        if ('' === $sessionUuid) {
            return $this->badRequest('Missing `sessionUuid`.');
        }

        $session = $this->chatService->loadSession($sessionUuid);
        if (null === $session) {
            return new JsonResponse(['error' => ['message' => 'Session not found.']], 404);
        }

        return new JsonResponse(['session' => $session]);
    }

    public function deleteSessionAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->guardPermission();
        if (null !== $denied) {
            return $denied;
        }

        $params = $this->parsedBody($request);
        $sessionUuid = $this->stringParam($params, 'sessionUuid');
        if ('' === $sessionUuid) {
            return $this->badRequest('Missing `sessionUuid`.');
        }

        $ok = $this->chatService->deleteSession($sessionUuid);
        if (!$ok) {
            return new JsonResponse(['error' => ['message' => 'Session not found.']], 404);
        }

        return new JsonResponse(['deleted' => true]);
    }

    public function availableModelsAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->guardPermission();
        if (null !== $denied) {
            return $denied;
        }

        return new JsonResponse($this->chatCatalogService->getModelCatalog());
    }

    public function helpAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->guardPermission();
        if (null !== $denied) {
            return $denied;
        }

        return new JsonResponse($this->chatHelpService->getHelp());
    }

    public function availableTemplatesAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->guardPermission();
        if (null !== $denied) {
            return $denied;
        }

        return new JsonResponse(['templates' => $this->chatCatalogService->getStarterTemplates()]);
    }

    public function availableLanguagesAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->guardPermission();
        if (null !== $denied) {
            return $denied;
        }

        $params = $this->parsedBody($request);
        $pageId = (int) $this->stringParam($params, 'pageId');

        return new JsonResponse(['languages' => $this->chatCatalogService->getAvailableLanguages($pageId)]);
    }

    public function applyConfirmationsAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->guardPermission();
        if (null !== $denied) {
            return $denied;
        }

        $params = $this->parsedBody($request);
        $sessionUuid = $this->stringParam($params, 'sessionUuid');
        if ('' === $sessionUuid) {
            return $this->badRequest('Missing `sessionUuid` for confirm.');
        }
        $limited = $this->guardRateLimit($sessionUuid);
        if (null !== $limited) {
            return $limited;
        }

        $approvals = $this->parseApprovals($params);
        if ([] === $approvals) {
            return $this->badRequest('Missing or empty `approvals` payload.');
        }

        $result = $this->chatService->applyConfirmations($sessionUuid, $approvals, $request);

        return $this->turnResultToResponse($result);
    }

    private function guardRateLimit(string $sessionUuid): ?ResponseInterface
    {
        $beUser = $this->backendUserService->getBackendUser();
        $identifier = 'cheddi_turn_'.(int) ($beUser?->user['uid'] ?? 0);

        try {
            $this->rateLimiter->checkAndIncrement($identifier);
        } catch (\RuntimeException $e) {
            $this->logger->warning('ChEddi: chat turn rate limit exceeded', [
                'identifier' => $identifier,
                'reason' => $e->getMessage(),
            ]);

            return new JsonResponse(
                TurnResult::error($sessionUuid, $e->getMessage(), 'rateLimited')->toArray(),
                429,
            );
        }

        return null;
    }

    private function guardPermission(): ?ResponseInterface
    {
        if (!$this->backendUserService->checkPermissions(self::FEATURE_FLAG)) {
            return new JsonResponse(
                ['error' => ['message' => 'Chat interface not enabled for this BE user group.']],
                403,
            );
        }

        return null;
    }

    private function guardModelPermission(string $model): ?ResponseInterface
    {
        if ('' === $model) {
            return null;
        }
        if (!$this->backendUserService->checkPermissions('tx_aisuite_models:'.$model)) {
            return new JsonResponse(
                ['error' => ['message' => 'Selected chat model is not allowed for this BE user group.']],
                403,
            );
        }

        return null;
    }

    private function turnResultToResponse(TurnResult $result): ResponseInterface
    {
        $statusCode = TurnResult::STATUS_ERROR === $result->status ? 422 : 200;

        return new JsonResponse($result->toArray(), $statusCode);
    }

    private function badRequest(string $message): ResponseInterface
    {
        return new JsonResponse(['error' => ['message' => $message]], 400);
    }

    /**
     * @return array<string, mixed>
     */
    private function parsedBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function stringParam(array $params, string $key): string
    {
        $value = $params[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<array{toolCallId: string, approved: bool}>
     */
    private function parseApprovals(array $params): array
    {
        $raw = $params['approvals'] ?? null;
        if (is_string($raw) && '' !== $raw) {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $toolCallId = $item['toolCallId'] ?? '';
            if (!is_string($toolCallId) || '' === $toolCallId) {
                continue;
            }
            $result[] = [
                'toolCallId' => $toolCallId,
                'approved' => (bool) ($item['approved'] ?? false),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function nullableStringParam(array $params, string $key): ?string
    {
        if (!array_key_exists($key, $params)) {
            return null;
        }
        $value = $params[$key];
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
