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

use TYPO3\CMS\Core\SingletonInterface;

class ChatWriteCaptureService implements SingletonInterface
{
    private bool $active = false;

    /**
     * @var list<array{table: string, uid: int, action: string}>
     */
    private array $captured = [];

    public function begin(): void
    {
        $this->active = true;
        $this->captured = [];
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function capture(string $table, int $uid, string $action): void
    {
        if (!$this->active || '' === $table || $uid <= 0) {
            return;
        }

        foreach ($this->captured as $existing) {
            if ($existing['table'] === $table && $existing['uid'] === $uid) {
                return;
            }
        }

        $this->captured[] = ['table' => $table, 'uid' => $uid, 'action' => $action];
    }

    /**
     * @return list<array{table: string, uid: int, action: string}>
     */
    public function flush(): array
    {
        $captured = $this->captured;
        $this->captured = [];

        return $captured;
    }

    public function end(): void
    {
        $this->active = false;
        $this->captured = [];
    }
}
