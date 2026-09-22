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
use AutoDudes\Cheddi\Service\Chat\CsvDownloadPayload;
use Mcp\Types\CallToolResult;

final class CreateCsvDownloadTool extends AbstractChatTool
{
    public const NAME = 'createCsvDownload';

    protected bool $readOnlyHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly CsvDownloadPayload $payload,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Offer tabular data to the editor as a CSV file download. '
            .'Use only when the editor asks for a CSV, Excel or spreadsheet file.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filename' => ['type' => 'string', 'description' => 'File name without path, e.g. "pages-without-description.csv".'],
                'columns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Column headings.'],
                'rows' => [
                    'type' => 'array',
                    'items' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'description' => 'One list of cell values per row, in column order.',
                ],
            ],
            'required' => ['columns', 'rows'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $payload = $this->payload->normalize($params);
        if (\is_string($payload)) {
            return $this->textError($payload);
        }

        $rowCount = \count($payload['rows']) - 1;

        return $this->structuredResult(
            sprintf('The CSV file "%s" with %d rows is offered to the editor as a download.', $payload['filename'], $rowCount),
            ['download' => ['filename' => $payload['filename'], 'rowCount' => $rowCount]],
        );
    }
}
