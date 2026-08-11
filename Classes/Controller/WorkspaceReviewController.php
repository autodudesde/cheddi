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
use AutoDudes\AiSuiteMcp\Mcp\Service\WorkspaceComparisonService;
use AutoDudes\Cheddi\Domain\Repository\ChatSessionRepository;
use AutoDudes\Cheddi\Service\Chat\WorkspaceReviewService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;

#[AsController]
final class WorkspaceReviewController
{
    private const FEATURE_FLAG = 'tx_aisuite_features:enable_cheddi_interface';

    public function __construct(
        private readonly ChatSessionRepository $sessionRepository,
        private readonly WorkspaceReviewService $reviewService,
        private readonly WorkspaceComparisonService $comparisonService,
        private readonly BackendUserService $backendUserService,
    ) {}

    public function listChangesAction(ServerRequestInterface $request): ResponseInterface
    {
        $session = $this->resolveSession($request);
        if (!is_int($session)) {
            return $session;
        }

        if (!$this->comparisonService->isWorkspacesLoaded()) {
            return new JsonResponse(['changes' => [], 'workspacesAvailable' => false]);
        }

        return new JsonResponse([
            'changes' => $this->reviewService->describeChanges($session),
            'workspacesAvailable' => true,
        ]);
    }

    public function publishAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->applyAction($request, true);
    }

    public function discardAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->applyAction($request, false);
    }

    private function applyAction(ServerRequestInterface $request, bool $publish): ResponseInterface
    {
        $session = $this->resolveSession($request);
        if (!is_int($session)) {
            return $session;
        }

        if (!$this->comparisonService->isWorkspacesLoaded()) {
            return $this->error('Workspaces are not available.', 409);
        }

        $uids = $this->changeUidsParam($request);
        if ([] === $uids) {
            return $this->error('Missing or empty `uids` field.', 400);
        }

        try {
            $outcome = $this->reviewService->apply($session, $uids, $publish);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 403);
        }

        return new JsonResponse([
            'applied' => $outcome['applied'],
            'errors' => $outcome['errors'],
            'changes' => $this->reviewService->describeChanges($session),
        ]);
    }

    private function resolveSession(ServerRequestInterface $request): int|ResponseInterface
    {
        if (!$this->backendUserService->checkPermissions(self::FEATURE_FLAG)) {
            return $this->error('ChEddi is not enabled for this backend user.', 403);
        }

        $beUser = $this->backendUserService->getBackendUser();
        $beUserUid = (int) ($beUser->user['uid'] ?? 0);
        if (0 === $beUserUid) {
            return $this->error('No backend user in context.', 403);
        }

        $sessionUuid = $this->stringParam($request, 'sessionUuid');
        if ('' === $sessionUuid) {
            return $this->error('Missing `sessionUuid` field.', 400);
        }

        $session = $this->sessionRepository->findByUuidForUser($sessionUuid, $beUserUid);
        if (null === $session) {
            return $this->error('Chat session not found.', 404);
        }

        return $session->uid;
    }

    /**
     * @return list<int>
     */
    private function changeUidsParam(ServerRequestInterface $request): array
    {
        $params = $request->getParsedBody();
        if (!is_array($params)) {
            return [];
        }

        $raw = $params['uids'] ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $uids = [];
        foreach ($raw as $uid) {
            if (is_numeric($uid) && (int) $uid > 0) {
                $uids[] = (int) $uid;
            }
        }

        return $uids;
    }

    private function stringParam(ServerRequestInterface $request, string $key): string
    {
        $params = $request->getParsedBody();
        if (!is_array($params)) {
            return '';
        }

        $value = $params[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    private function error(string $message, int $status): ResponseInterface
    {
        return new JsonResponse(['error' => ['message' => $message]], $status);
    }
}
