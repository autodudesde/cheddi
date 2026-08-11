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

use AutoDudes\AiSuiteMcp\Mcp\Service\RemoteMediaService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use AutoDudes\Cheddi\Service\Chat\HtmlTextExtractor;
use Mcp\Types\CallToolResult;

final class ReadWebPageTool extends AbstractChatTool
{
    public const NAME = 'readWebPage';

    private const MAX_CHARS = 20000;
    private const MAX_BYTES = 5242880;
    private const TIMEOUT_SECONDS = 20;

    protected bool $readOnlyHint = true;
    protected bool $openWorldHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly RemoteMediaService $remoteMediaService,
        private readonly HtmlTextExtractor $htmlTextExtractor,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Fetch the content of a specific web page the editor named or linked (e.g. "take the '
            .'text from this page and turn it into a landing page"). Pass the full URL. Use searchWeb '
            .'instead when you first need to find the right page.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'The full http(s) URL to read.'],
            ],
            'required' => ['url'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $url = trim((string) ($params['url'] ?? ''));
        if ('' === $url) {
            return $this->textError('readWebPage requires a `url`.');
        }

        try {
            // The URL comes from the model: RemoteMediaService is the SSRF boundary, not a plain request.
            $fetched = $this->remoteMediaService->fetch($url, self::MAX_BYTES, [], self::TIMEOUT_SECONDS);
        } catch (\RuntimeException $e) {
            return $this->textError(sprintf('Could not read %s: %s', $url, $e->getMessage()));
        }

        try {
            $body = (string) file_get_contents($fetched->tempFilePath);
        } finally {
            @unlink($fetched->tempFilePath);
        }

        $text = $this->htmlTextExtractor->extract($body, self::MAX_CHARS);
        if ('' === $text) {
            return $this->textError(sprintf('%s returned no readable text.', $url));
        }

        $title = $this->htmlTextExtractor->extractTitle($body);

        return $this->structuredResult($text, ['webSearch' => ['sources' => [[
            'title' => '' !== $title ? $title : $fetched->sourceUrl,
            'url' => $fetched->sourceUrl,
            'snippet' => '',
            'text' => $text,
        ]]]]);
    }
}
