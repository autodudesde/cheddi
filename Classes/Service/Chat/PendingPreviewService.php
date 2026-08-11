<?php

declare(strict_types=1);

namespace AutoDudes\Cheddi\Service\Chat;

use AutoDudes\AiSuiteMcp\Mcp\Service\RecordPreviewService;
use AutoDudes\AiSuiteMcp\Mcp\Utility\BatchDefaults;
use AutoDudes\AiSuiteMcp\Mcp\Utility\RecordsArgumentDecoder;
use Psr\Log\LoggerInterface;

class PendingPreviewService
{
    private const MAX_RECORDS = 10;

    private const MAX_FIELDS_PER_RECORD = 12;

    private const MAX_VALUE_LENGTH = 200;

    /**
     * @var array<string, true>
     */
    private array $reportedFailures = [];

    public function __construct(
        private readonly RecordPreviewService $recordPreview,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     *
     * @return null|array<string, mixed>
     */
    public function build(string $toolName, array $arguments): ?array
    {
        try {
            return match ($toolName) {
                'writeRecords' => $this->buildWrite($arguments),
                'deleteRecords' => $this->buildExisting($this->withBatchTable($arguments, 'records'), 'records', 'delete'),
                'copyRecords' => $this->buildExisting($arguments, 'copies', 'copy'),
                'moveRecords' => $this->buildExisting($this->withBatchTable($arguments, 'moves'), 'moves', 'move'),
                'localizeRecord' => $this->buildLocalize($arguments),
                'savePageTree' => $this->buildPageTree($arguments),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->report($toolName, 'no preview card, the raw JSON is shown instead', $e);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function invalidReason(string $toolName, array $arguments): ?string
    {
        $scan = $this->scanWriteRecords($toolName, $arguments);

        if ($scan['writable'] > 0 || [] === $scan['notes']) {
            return null;
        }

        return implode(' | ', $scan['notes']);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    public function invalidNotes(string $toolName, array $arguments): array
    {
        return $this->scanWriteRecords($toolName, $arguments)['notes'];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{notes: list<string>, writable: int}
     */
    private function scanWriteRecords(string $toolName, array $arguments): array
    {
        if ('writeRecords' !== $toolName) {
            return ['notes' => [], 'writable' => 0];
        }

        try {
            $records = $this->decodeWriteRecords($arguments);
            if ([] === $records) {
                return ['notes' => [], 'writable' => 0];
            }

            $notes = [];
            $writable = 0;
            foreach ($this->recordPreview->describeWrite(array_values($records), self::MAX_VALUE_LENGTH) as $index => $record) {
                $action = $record['action'] ?? '';
                if ('invalid' === $action) {
                    $note = $record['note'] ?? null;
                    $notes[] = sprintf('#%d: %s', $index + 1, is_string($note) ? $note : 'invalid');
                } elseif (in_array($action, ['create', 'update'], true)) {
                    ++$writable;
                }
            }

            return ['notes' => $notes, 'writable' => $writable];
        } catch (\Throwable $e) {
            $this->report($toolName, 'gate opened, the tool validates the call itself', $e);

            return ['notes' => [], 'writable' => 0];
        }
    }

    private function report(string $toolName, string $consequence, \Throwable $e): void
    {
        $key = $toolName.'|'.$e->getMessage();
        if (isset($this->reportedFailures[$key])) {
            return;
        }
        $this->reportedFailures[$key] = true;

        $this->logger->warning('PendingPreview: could not read tool call arguments', [
            'tool' => $toolName,
            'consequence' => $consequence,
            'reason' => $e->getMessage(),
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<int, mixed>
     */
    private function decodeWriteRecords(array $arguments): array
    {
        return BatchDefaults::applyTable(
            RecordsArgumentDecoder::decode($arguments['records'] ?? null),
            (string) ($arguments['table'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function withBatchTable(array $arguments, string $key): array
    {
        $entries = $arguments[$key] ?? null;
        if (!is_array($entries)) {
            return $arguments;
        }
        $arguments[$key] = BatchDefaults::applyTable($entries, (string) ($arguments['table'] ?? ''));

        return $arguments;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return null|array<string, mixed>
     */
    private function buildWrite(array $arguments): ?array
    {
        $records = $this->decodeWriteRecords($arguments);
        if ([] === $records) {
            return null;
        }

        $total = count($records);
        $described = $this->recordPreview->describeWrite(
            array_slice(array_values($records), 0, self::MAX_RECORDS),
            self::MAX_VALUE_LENGTH,
        );

        return $this->wrap(array_map($this->capFields(...), $described), $total);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return null|array<string, mixed>
     */
    private function buildExisting(array $arguments, string $key, string $action): ?array
    {
        $items = $arguments[$key] ?? null;
        if (!is_array($items) || [] === $items) {
            return null;
        }

        $total = count($items);
        $described = [];
        foreach (array_slice(array_values($items), 0, self::MAX_RECORDS) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $table = (string) ($item['table'] ?? '');
            $uid = isset($item['uid']) && is_numeric($item['uid']) ? (int) $item['uid'] : null;
            if ('' === $table || null === $uid) {
                continue;
            }

            $described[] = $this->recordPreview->describeExisting(
                $table,
                $uid,
                $action,
                $this->targetNote($item),
            );
        }

        if ([] === $described) {
            return null;
        }

        return $this->wrap($described, $total);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return null|array<string, mixed>
     */
    private function buildLocalize(array $arguments): ?array
    {
        $table = (string) ($arguments['table'] ?? '');
        $uid = isset($arguments['uid']) && is_numeric($arguments['uid']) ? (int) $arguments['uid'] : null;
        if ('' === $table || null === $uid) {
            return null;
        }

        $language = $arguments['targetLanguage'] ?? $arguments['languageId'] ?? null;
        $note = is_scalar($language) ? sprintf('Target language: %s', (string) $language) : null;

        return $this->wrap([$this->recordPreview->describeExisting($table, $uid, 'localize', $note)], 1);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return null|array<string, mixed>
     */
    private function buildPageTree(array $arguments): ?array
    {
        $pages = $arguments['pages'] ?? null;
        if (!is_array($pages) || [] === $pages) {
            return null;
        }

        $flat = [];
        $this->flattenPages($pages, 0, $flat);
        if ([] === $flat) {
            return null;
        }

        $total = count($flat);
        $parentPageId = isset($arguments['parentPageId']) && is_numeric($arguments['parentPageId'])
            ? (int) $arguments['parentPageId']
            : null;

        return [
            'kind' => 'pageTree',
            'pages' => array_slice($flat, 0, self::MAX_RECORDS),
            'parentPageId' => $parentPageId,
            'parentLabel' => null === $parentPageId ? null : $this->pageLabel($parentPageId),
            'total' => $total,
            'truncated' => $total > self::MAX_RECORDS,
        ];
    }

    /**
     * @param array<mixed>               $pages
     * @param list<array<string, mixed>> $flat
     */
    private function flattenPages(array $pages, int $depth, array &$flat): void
    {
        foreach (array_values($pages) as $page) {
            if (!is_array($page)) {
                continue;
            }
            $title = is_scalar($page['title'] ?? null) ? trim((string) $page['title']) : '';
            if ('' === $title) {
                continue;
            }

            $flat[] = ['title' => $title, 'depth' => $depth];

            if (count($flat) >= self::MAX_RECORDS + 1) {
                return;
            }

            $children = $page['children'] ?? null;
            if (is_array($children) && [] !== $children) {
                $this->flattenPages($children, $depth + 1, $flat);
            }
        }
    }

    private function pageLabel(int $pageId): ?string
    {
        $page = $this->recordPreview->describeExisting('pages', $pageId, 'target');
        $label = (string) ($page['recordLabel'] ?? '');

        return '' === $label ? null : $label;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function targetNote(array $item): ?string
    {
        $targetPid = $item['targetPid'] ?? $item['pid'] ?? null;
        if (!is_numeric($targetPid)) {
            return null;
        }

        $page = $this->recordPreview->describeExisting('pages', (int) $targetPid, 'target');
        $label = (string) ($page['recordLabel'] ?? '');

        return '' === $label
            ? sprintf('Target page: %d', (int) $targetPid)
            : sprintf('Target page: %s (%d)', $label, (int) $targetPid);
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function capFields(array $record): array
    {
        $fields = $record['fields'] ?? [];
        if (!is_array($fields) || count($fields) <= self::MAX_FIELDS_PER_RECORD) {
            return $record;
        }

        $record['hiddenFieldCount'] = count($fields) - self::MAX_FIELDS_PER_RECORD;
        $record['fields'] = array_slice($fields, 0, self::MAX_FIELDS_PER_RECORD);

        return $record;
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return array<string, mixed>
     */
    private function wrap(array $records, int $total): array
    {
        return [
            'kind' => 'records',
            'records' => $records,
            'total' => $total,
            'truncated' => $total > count($records),
        ];
    }
}
