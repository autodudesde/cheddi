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

use Mcp\Types\CallToolResult;

final class SearchWebTool extends AbstractWebResearchTool
{
    public const NAME = 'searchWeb';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Research a topic on the open web and return a short, cited summary. '
            .'Use this when the editor asks for current information, facts, or examples that are not '
            .'already in the TYPO3 content or the conversation. The result includes source URLs — '
            .'always present those to the editor. Do not use it to read a specific page the editor '
            .'named (use readWebPage for that).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'The research question in natural language.'],
                'maxSearches' => ['type' => 'integer', 'default' => 5, 'description' => 'Upper bound on the number of searches (1-15). Keep it low for simple lookups.'],
            ],
            'required' => ['query'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $query = trim((string) ($params['query'] ?? ''));
        if ('' === $query) {
            return $this->textError('searchWeb requires a `query`.');
        }

        $maxSearches = (int) ($params['maxSearches'] ?? 5);
        $result = $this->webSearchRequestService->search($query, $maxSearches > 0 ? $maxSearches : 5);

        return $this->webResult($result);
    }
}
