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
use AutoDudes\Cheddi\Service\Chat\WebSearchRequestService;
use Mcp\Types\CallToolResult;

abstract class AbstractWebResearchTool extends AbstractChatTool
{
    protected bool $readOnlyHint = true;
    protected bool $openWorldHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        protected readonly WebSearchRequestService $webSearchRequestService,
    ) {
        parent::__construct($mcpToolContext);
    }

    /**
     * @param array{summary: string, sources: list<array{title: string, url: string, snippet: string}>, isError: bool} $result
     */
    protected function webResult(array $result): CallToolResult
    {
        if ($result['isError']) {
            return $this->textError('Web research is currently unavailable.');
        }

        return [] === $result['sources']
            ? $this->textResult($result['summary'])
            : $this->structuredResult($result['summary'], ['webSearch' => ['sources' => $result['sources']]]);
    }
}
