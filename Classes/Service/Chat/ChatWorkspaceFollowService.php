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

use AutoDudes\Cheddi\Domain\Repository\ChatSessionRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

class ChatWorkspaceFollowService
{
    public function __construct(
        private readonly ChatOrientationService $orientationService,
        private readonly ChatSessionRepository $sessionRepository,
        private readonly ChatWorkspaceOptionsService $workspaceOptions,
    ) {}

    /**
     * @return array{changed: bool, reachable: bool, workspaceId: int}
     */
    public function follow(BackendUserAuthentication $backendUser, string $sessionUuid, string $choice): array
    {
        $current = (int) $backendUser->workspace;
        $target = $this->orientationService->resolveWorkspaceForDisplay($this->pinnedWorkspace($backendUser, $sessionUuid, $choice));

        if ($target['pending'] || $target['id'] === $current) {
            return ['changed' => false, 'reachable' => true, 'workspaceId' => $current];
        }

        if (false === $backendUser->checkWorkspace($target['id'])) {
            return ['changed' => false, 'reachable' => false, 'workspaceId' => $current];
        }

        $backendUser->setWorkspace($target['id']);

        return ['changed' => true, 'reachable' => true, 'workspaceId' => (int) $backendUser->workspace];
    }

    private function pinnedWorkspace(BackendUserAuthentication $backendUser, string $sessionUuid, string $choice): int
    {
        $session = '' === $sessionUuid ? null : $this->sessionRepository->findByUuidForUser($sessionUuid, (int) ($backendUser->user['uid'] ?? 0));
        if (null !== $session && !$session->deleted) {
            return $session->workspace;
        }

        foreach ($this->workspaceOptions->optionsFor($backendUser) as $option) {
            if ($option['value'] === $choice) {
                return $option['uid'];
            }
        }

        return 0;
    }
}
