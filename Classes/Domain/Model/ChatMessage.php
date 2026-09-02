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

class ChatMessage
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_TOOL = 'tool';
    public const ROLE_SUMMARY = 'summary';

    public const TOOL_STATUS_PENDING = 'pending';
    public const TOOL_STATUS_APPROVED = 'approved';
    public const TOOL_STATUS_REJECTED = 'rejected';
    public const TOOL_STATUS_DONE = 'done';
    public const TOOL_STATUS_FAILED = 'failed';

    public function __construct(
        public int $uid = 0,
        public int $session = 0,
        public int $sort = 0,
        public string $role = '',
        public string $content = '',
        public string $toolCalls = '',
        public string $toolCallId = '',
        public string $toolStatus = '',
        public int $crdate = 0,
        public int $tstamp = 0,
        public string $attachments = '',
        public string $providerItems = '',
        public string $sources = '',
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            uid: (int) ($row['uid'] ?? 0),
            session: (int) ($row['session'] ?? 0),
            sort: (int) ($row['sort'] ?? 0),
            role: (string) ($row['role'] ?? ''),
            content: (string) ($row['content'] ?? ''),
            toolCalls: (string) ($row['tool_calls'] ?? ''),
            toolCallId: (string) ($row['tool_call_id'] ?? ''),
            toolStatus: (string) ($row['tool_status'] ?? ''),
            crdate: (int) ($row['crdate'] ?? 0),
            tstamp: (int) ($row['tstamp'] ?? 0),
            attachments: (string) ($row['attachments'] ?? ''),
            providerItems: (string) ($row['provider_items'] ?? ''),
            sources: (string) ($row['sources'] ?? ''),
        );
    }
}
