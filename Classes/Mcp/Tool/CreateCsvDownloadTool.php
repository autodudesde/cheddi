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
            .'Use only when the editor asks for a CSV, Excel or spreadsheet file. '
            .'When every row is a record of one table, give `records` instead of `rows`: the file reads the records itself, while copied rows get lost.';
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
                    'description' => 'One list of cell values per row, in column order. Only for data that is not records of one table.',
                ],
                'records' => [
                    'type' => 'object',
                    'description' => 'One object: the records of one table to export, selected like readRecords. Without pid, rootPageId or filters every record you can read is exported.',
                    'properties' => [
                        'table' => ['type' => 'string'],
                        'fields' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'One field per column, in column order.'],
                        'pid' => ['type' => 'integer'],
                        'rootPageId' => ['type' => 'integer'],
                        'filters' => ['type' => 'object', 'description' => 'Field name => value, exact match; "" finds empty fields.'],
                    ],
                    'required' => ['table', 'fields'],
                ],
            ],
            'required' => ['columns'],
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
