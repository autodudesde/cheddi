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

use AutoDudes\AiSuite\Service\CsvExportService;

class CsvDownloadPayload
{
    public const MAX_ROWS = 2000;
    public const MAX_COLUMNS = 50;

    public function __construct(
        private readonly CsvExportService $csvExportService,
    ) {}

    /**
     * @param array<mixed> $arguments
     *
     * @return array{filename: string, rows: list<list<string>>}|string
     */
    public function normalize(array $arguments): array|string
    {
        $columns = $arguments['columns'] ?? null;
        if (!\is_array($columns) || [] === $columns || !array_is_list($columns)) {
            return '`columns` must be a non-empty list of column headings.';
        }
        if (\count($columns) > self::MAX_COLUMNS) {
            return sprintf('A CSV download holds at most %d columns; leave out the columns the editor did not ask for.', self::MAX_COLUMNS);
        }

        $rows = $arguments['rows'] ?? null;
        if (!\is_array($rows) || !array_is_list($rows)) {
            return '`rows` must be a list of rows, each row a list of cell values in column order.';
        }
        if (\count($rows) > self::MAX_ROWS) {
            return sprintf('A CSV download holds at most %d rows; split the data into several files.', self::MAX_ROWS);
        }

        $width = \count($columns);
        $header = $this->cells($columns, $width);
        if (null === $header) {
            return '`columns` may only contain plain text headings.';
        }

        $normalized = [$header];
        foreach ($rows as $index => $row) {
            if (!\is_array($row) || !array_is_list($row)) {
                return sprintf('Row %d is not a list of cell values.', $index + 1);
            }
            if (\count($row) > $width) {
                return sprintf('Row %d has %d cells, but only %d columns are defined.', $index + 1, \count($row), $width);
            }
            $cells = $this->cells($row, $width);
            if (null === $cells) {
                return sprintf('Row %d contains a nested value; every cell must be text or a number.', $index + 1);
            }
            $normalized[] = $cells;
        }

        return [
            'filename' => $this->csvExportService->safeFilename((string) ($arguments['filename'] ?? '')),
            'rows' => $normalized,
        ];
    }

    /**
     * @param list<mixed> $cells
     *
     * @return null|list<string>
     */
    private function cells(array $cells, int $width): ?array
    {
        $result = [];
        foreach ($cells as $cell) {
            if (null === $cell) {
                $result[] = '';

                continue;
            }
            if (!\is_scalar($cell)) {
                return null;
            }
            $result[] = \is_bool($cell) ? ($cell ? '1' : '0') : (string) $cell;
        }

        return array_pad($result, $width, '');
    }
}
