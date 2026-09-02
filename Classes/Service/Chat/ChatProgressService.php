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
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

class ChatProgressService
{
    public const PHASE_THINKING = 'thinking';
    public const PHASE_TOOL = 'tool';
    public const PHASE_DONE = 'done';

    private const CACHE_PREFIX = 'cheddi_progress_';
    private const LIFETIME_SECONDS = 300;

    public function __construct(
        #[Autowire(service: 'cache.hash')]
        private readonly FrontendInterface $cache,
        private readonly ClockInterface $clock,
        private readonly BackendUserService $backendUserService,
    ) {}

    public function startTurn(string $sessionUuid): void
    {
        $this->write($sessionUuid, [
            'phase' => self::PHASE_THINKING,
            'tool' => null,
            'step' => 0,
            'total' => 0,
        ]);
    }

    public function toolStarted(string $sessionUuid, string $toolName, int $step, int $total): void
    {
        $this->write($sessionUuid, [
            'phase' => self::PHASE_TOOL,
            'tool' => $toolName,
            'step' => $step,
            'total' => $total,
        ]);
    }

    public function finishTurn(string $sessionUuid): void
    {
        $this->write($sessionUuid, [
            'phase' => self::PHASE_DONE,
            'tool' => null,
            'step' => 0,
            'total' => 0,
        ]);
    }

    /**
     * @return null|array{phase: string, tool: null|string, step: int, total: int, updatedAt: int}
     */
    public function read(string $sessionUuid): ?array
    {
        $data = $this->cache->get($this->cacheKey());
        if (!is_array($data)) {
            return null;
        }

        $recorded = $data['sessionUuid'] ?? null;
        // The drawer only learns its session uuid from the first answer, so it polls without one.
        if ('' !== $sessionUuid && is_string($recorded) && $recorded !== $sessionUuid) {
            return null;
        }

        $phase = $data['phase'] ?? null;
        $tool = $data['tool'] ?? null;

        return [
            'phase' => is_string($phase) ? $phase : self::PHASE_THINKING,
            'tool' => is_string($tool) && '' !== $tool ? $tool : null,
            'step' => is_numeric($data['step'] ?? null) ? (int) $data['step'] : 0,
            'total' => is_numeric($data['total'] ?? null) ? (int) $data['total'] : 0,
            'updatedAt' => is_numeric($data['updatedAt'] ?? null) ? (int) $data['updatedAt'] : 0,
        ];
    }

    private function currentUserUid(): int
    {
        $backendUser = $this->backendUserService->getBackendUser();

        return null === $backendUser ? 0 : (int) ($backendUser->user['uid'] ?? 0);
    }

    /**
     * @param array{phase: string, tool: null|string, step: int, total: int} $entry
     */
    private function write(string $sessionUuid, array $entry): void
    {
        $uid = $this->currentUserUid();
        if (0 === $uid) {
            return;
        }

        $entry['sessionUuid'] = $sessionUuid;
        $entry['updatedAt'] = $this->clock->now()->getTimestamp();
        $this->cache->set($this->cacheKey(), $entry, [], self::LIFETIME_SECONDS);
    }

    private function cacheKey(): string
    {
        return self::CACHE_PREFIX.$this->currentUserUid();
    }
}
