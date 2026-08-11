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

namespace AutoDudes\Cheddi\Domain\Model;

class ChatSession
{
    public function __construct(
        public int $uid = 0,
        public string $sessionUuid = '',
        public int $beUser = 0,
        public string $title = '',
        public string $model = '',
        public int $lastActivity = 0,
        public int $crdate = 0,
        public int $tstamp = 0,
        public bool $deleted = false,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            uid: (int) ($row['uid'] ?? 0),
            sessionUuid: (string) ($row['session_uuid'] ?? ''),
            beUser: (int) ($row['be_user'] ?? 0),
            title: (string) ($row['title'] ?? ''),
            model: (string) ($row['model'] ?? ''),
            lastActivity: (int) ($row['last_activity'] ?? 0),
            crdate: (int) ($row['crdate'] ?? 0),
            tstamp: (int) ($row['tstamp'] ?? 0),
            deleted: (bool) ($row['deleted'] ?? false),
        );
    }
}
