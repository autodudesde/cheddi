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

namespace AutoDudes\Cheddi\Mcp\Tool;

use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use AutoDudes\Cheddi\Service\Chat\AttachmentService;
use Mcp\Types\CallToolResult;

final class ReadAttachmentTextTool extends AbstractChatTool
{
    public const NAME = 'readAttachmentText';

    protected bool $readOnlyHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly AttachmentService $attachmentService,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Read the text of a file the editor attached to the conversation. '
            .'Use the sys_file uid given in the [Attachments] block of the user message. '
            .'Supports PDF, Word, spreadsheets and plain text. Images cannot be read. '
            .'If the answer is truncated, call again with charOffset to continue.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'uid' => ['type' => 'integer', 'description' => 'The sys_file uid of the attachment.'],
                'charOffset' => ['type' => 'integer', 'default' => 0, 'description' => 'Resume reading at this character offset.'],
            ],
            'required' => ['uid'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $uid = (int) ($params['uid'] ?? 0);
        if ($uid <= 0) {
            return $this->textError('readAttachmentText requires a positive `uid`.');
        }

        $result = $this->attachmentService->readText($uid, max(0, (int) ($params['charOffset'] ?? 0)));

        return $result['isError']
            ? $this->textError($result['content'])
            : $this->textResult($result['content']);
    }
}
