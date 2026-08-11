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

use AutoDudes\Cheddi\Domain\Model\ChatMessage;
use AutoDudes\Cheddi\Domain\Model\Dto\ChatToolContext;
use Psr\Log\LoggerInterface;

class ContextPrefillService
{
    private const CALL_ID_PREFIX = 'cheddi_ctx_';

    private const MAX_CONTENT_CHARS = 8000;

    public function __construct(
        private readonly ContextCollector $contextCollector,
        private readonly ToolBridge $toolBridge,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return list<array{role: string, content: string, toolCalls?: array<int, array<string, mixed>>, toolCallId?: string, toolStatus?: string}>
     *                                                                                                                                            empty, or an assistant+tool pair in API shape
     */
    public function buildSyntheticMessages(ChatToolContext $context): array
    {
        $record = $this->contextCollector->focusedRecord($context->request);
        if (null === $record) {
            return [];
        }

        $arguments = ['table' => $record['table'], 'uid' => $record['uid'], 'raw' => true];
        $result = $this->toolBridge->execute('readRecords', $arguments, $context);

        if ($result['isError'] || '' === trim($result['content'])) {
            $this->logger->info('ChEddi: skipped context pre-fill', [
                'table' => $record['table'],
                'uid' => $record['uid'],
                'isError' => $result['isError'],
            ]);

            return [];
        }

        $callId = self::CALL_ID_PREFIX.$record['table'].'_'.$record['uid'];

        return [
            [
                'role' => ChatMessage::ROLE_ASSISTANT,
                'content' => '',
                'toolCalls' => [[
                    'id' => $callId,
                    'name' => 'readRecords',
                    'arguments' => $arguments,
                ]],
            ],
            [
                'role' => ChatMessage::ROLE_TOOL,
                'content' => $this->capContent($result['content']),
                'toolCallId' => $callId,
                'toolStatus' => ChatMessage::TOOL_STATUS_DONE,
            ],
        ];
    }

    private function capContent(string $content): string
    {
        if (mb_strlen($content) <= self::MAX_CONTENT_CHARS) {
            return $content;
        }

        return mb_substr($content, 0, self::MAX_CONTENT_CHARS)
            ."\n\n[Truncated. Call readRecords for the full record if you need more.]";
    }
}
