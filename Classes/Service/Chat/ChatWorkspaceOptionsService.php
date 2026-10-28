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

use AutoDudes\AiSuiteMcp\Mcp\Service\McpWorkspaceOptionsService;
use AutoDudes\Cheddi\Domain\Repository\ChatWorkspaceRepository;
use AutoDudes\Cheddi\Exception\WorkspaceAccessException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

class ChatWorkspaceOptionsService
{
    public const PERSONAL = 'personal';

    public function __construct(
        private readonly McpWorkspaceOptionsService $workspaceOptions,
        private readonly ChatWorkspaceRepository $chatWorkspaceRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return list<array{value: string, uid: int, title: string, personal: bool, pending: bool}>
     */
    public function optionsFor(BackendUserAuthentication $backendUser): array
    {
        $personalUid = $this->ownWorkspaceUid($backendUser);

        $options = [];
        $personalOffered = false;
        foreach ($this->workspaceOptions->availableFor($backendUser) as $workspace) {
            if ($workspace['uid'] === $personalUid) {
                $options[] = $this->option(self::PERSONAL, $workspace['uid'], $workspace['title'], true, false);
                $personalOffered = true;
            } else {
                $options[] = $this->option((string) $workspace['uid'], $workspace['uid'], $workspace['title'], false, false);
            }
        }

        if (!$personalOffered) {
            $options[] = $this->option(self::PERSONAL, 0, '', true, true);
        }

        return $options;
    }

    public function pin(BackendUserAuthentication $backendUser, string $choice): ?int
    {
        if (self::PERSONAL === $choice) {
            return $this->ensureOwnWorkspaceId($backendUser);
        }

        foreach ($this->optionsFor($backendUser) as $option) {
            if (!$option['personal'] && $option['value'] === $choice) {
                return $option['uid'];
            }
        }

        return null;
    }

    public function ownWorkspaceUid(BackendUserAuthentication $backendUser): ?int
    {
        return $this->chatWorkspaceRepository->findOwnedBy((int) ($backendUser->user['uid'] ?? 0));
    }

    /**
     * @throws WorkspaceAccessException
     */
    public function ensureOwnWorkspaceId(BackendUserAuthentication $backendUser): int
    {
        $beUserUid = (int) ($backendUser->user['uid'] ?? 0);

        try {
            $existing = $this->ownWorkspaceUid($backendUser);
            if (null !== $existing) {
                return $existing;
            }

            $workspaceId = $this->chatWorkspaceRepository->createFor($beUserUid, $this->displayName($backendUser));
            $this->logger->info('ChEddi: created the draft workspace of a backend user', [
                'beUserUid' => $beUserUid,
                'workspace' => $workspaceId,
            ]);

            return $workspaceId;
        } catch (\Throwable $e) {
            $this->logger->error('ChEddi: the draft workspace could neither be found nor created, nothing is written to live', [
                'beUserUid' => $beUserUid,
                'error' => $e->getMessage(),
            ]);

            throw new WorkspaceAccessException('Write mode "workspace" requires a draft workspace, but none could be found or created for this backend user. Nothing was written to the live site.', 0, $e);
        }
    }

    private function displayName(BackendUserAuthentication $backendUser): string
    {
        $realName = trim((string) ($backendUser->user['realName'] ?? ''));
        if ('' !== $realName) {
            return $realName;
        }

        $username = trim((string) ($backendUser->user['username'] ?? ''));

        return '' !== $username ? $username : '#'.(int) ($backendUser->user['uid'] ?? 0);
    }

    /**
     * @return array{value: string, uid: int, title: string, personal: bool, pending: bool}
     */
    private function option(string $value, int $uid, string $title, bool $personal, bool $pending): array
    {
        return ['value' => $value, 'uid' => $uid, 'title' => $title, 'personal' => $personal, 'pending' => $pending];
    }
}
