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

namespace AutoDudes\Cheddi\Domain\Model\Dto;

final class ChatTurnAnswer
{
    public const TYPE_CHAT_TURN = 'ChatTurn';
    public const TYPE_ERROR = 'Error';

    /**
     * @param list<array{id: string, name: string, arguments: array<string, mixed>}> $toolCalls
     * @param null|array{summaryContent: string}                                     $historySummary
     * @param list<array<string, mixed>>                                             $providerItems
     * @param list<array{title: string, url: string, snippet: string}>               $sources
     */
    public function __construct(
        public readonly string $type,
        public readonly string $assistantText,
        public readonly array $toolCalls,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $totalCredits,
        public readonly ?int $remainingCredits,
        public readonly int $contextWindowTokens,
        public readonly bool $creditsExhausted,
        public readonly ?string $errorMessage,
        public readonly ?array $historySummary,
        public readonly bool $lowBalance = false,
        public readonly ?string $chatErrorCode = null,
        public readonly array $providerItems = [],
        public readonly array $sources = [],
    ) {}

    /**
     * @param array<string, mixed> $envelope
     */
    public static function fromResponseData(array $envelope): self
    {
        $type = (string) ($envelope['type'] ?? self::TYPE_ERROR);

        /** @var array<string, mixed> $body */
        $body = is_array($envelope['body'] ?? null) ? $envelope['body'] : [];

        if (self::TYPE_ERROR === $type) {
            return new self(
                type: self::TYPE_ERROR,
                assistantText: '',
                toolCalls: [],
                inputTokens: 0,
                outputTokens: 0,
                totalCredits: 0,
                remainingCredits: null,
                contextWindowTokens: 0,
                creditsExhausted: false,
                errorMessage: is_string($body['message'] ?? null) ? $body['message'] : null,
                historySummary: null,
                chatErrorCode: is_string($body['errorType'] ?? null) ? $body['errorType'] : null,
            );
        }

        /** @var array<string, mixed> $assistantMessage */
        $assistantMessage = is_array($body['assistantMessage'] ?? null) ? $body['assistantMessage'] : [];

        /** @var array<string, mixed> $usage */
        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

        /** @var array<string, mixed> $error */
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];

        return new self(
            type: self::TYPE_CHAT_TURN,
            assistantText: is_string($assistantMessage['text'] ?? null) ? $assistantMessage['text'] : '',
            toolCalls: self::normaliseToolCalls($assistantMessage['toolCalls'] ?? []),
            inputTokens: (int) ($usage['inputTokens'] ?? 0),
            outputTokens: (int) ($usage['outputTokens'] ?? 0),
            totalCredits: (int) ($usage['totalCredits'] ?? 0),
            remainingCredits: array_key_exists('remainingCredits', $usage) ? (int) $usage['remainingCredits'] : null,
            contextWindowTokens: (int) ($body['contextWindowTokens'] ?? 0),
            creditsExhausted: ($error['creditsExhausted'] ?? false) === true,
            errorMessage: null,
            historySummary: self::normaliseHistorySummary($body['historySummary'] ?? null),
            lowBalance: ($usage['lowBalance'] ?? false) === true,
            chatErrorCode: null,
            providerItems: self::normaliseProviderItems($assistantMessage['providerItems'] ?? []),
            sources: self::normaliseSources($body['sources'] ?? []),
        );
    }

    /**
     * @return list<array{title: string, url: string, snippet: string}>
     */
    private static function normaliseSources(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $sources = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $url = trim((string) ($entry['url'] ?? ''));
            if ('' === $url) {
                continue;
            }
            $sources[] = [
                'title' => (string) ($entry['title'] ?? $url),
                'url' => $url,
                'snippet' => (string) ($entry['snippet'] ?? ''),
            ];
        }

        return $sources;
    }

    /**
     * @return list<array{id: string, name: string, arguments: array<string, mixed>}>
     */
    private static function normaliseToolCalls(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $result = [];
        foreach ($raw as $call) {
            if (!is_array($call)) {
                continue;
            }
            $arguments = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
            $result[] = [
                'id' => (string) ($call['id'] ?? ''),
                'name' => (string) ($call['name'] ?? ''),
                'arguments' => $arguments,
            ];
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function normaliseProviderItems(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $items = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @return null|array{summaryContent: string}
     */
    private static function normaliseHistorySummary(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        return [
            'summaryContent' => (string) ($raw['summaryContent'] ?? ''),
        ];
    }
}
