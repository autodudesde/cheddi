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

final class TurnResult
{
    public const STATUS_FINAL = 'final';
    public const STATUS_CONTINUING = 'continuing';
    public const STATUS_NEEDS_CONFIRM = 'needsConfirm';
    public const STATUS_ERROR = 'error';
    public const STATUS_CREDITS_EXHAUSTED = 'creditsExhausted';
    public const STATUS_ABORTED = 'aborted';

    public const ABORT_REASON_TOOL_CAP_REACHED = 'toolCapReached';

    /**
     * @param list<array{id: string, name: string, arguments: array<string, mixed>, status?: string}>                                       $toolCalls
     * @param list<array{id: string, name: string, arguments: array<string, mixed>, severity: string, preview?: null|array<string, mixed>}> $pending
     * @param null|array<string, mixed>                                                                                                     $usage
     * @param null|array{summaryContent: string, replacedCount?: int}                                                                       $historySummary
     * @param list<array{key: string, params?: array<string, int|string>}|string>                                                           $notices
     * @param list<array{title: string, url: string, snippet: string}>                                                                      $sources
     * @param list<array{table: string, label: string, targets: list<array{label: string, url: string}>, omitted: int}>                     $navigationTargets
     * @param list<string>                                                                                                                  $touchedTables
     * @param null|array{key: string, params?: array<string, string>}                                                                       $confirmTarget
     * @param null|array{ratio: float, level: string}                                                                                       $contextFill
     */
    private function __construct(
        public readonly string $status,
        public readonly string $sessionUuid,
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly array $pending,
        public readonly ?array $usage,
        public readonly int $contextWindowTokens,
        public readonly ?array $historySummary,
        public readonly ?string $errorMessage,
        public readonly ?string $abortReason = null,
        public readonly array $notices = [],
        public readonly ?string $chatErrorCode = null,
        public readonly array $sources = [],
        public readonly array $navigationTargets = [],
        public readonly array $touchedTables = [],
        public readonly ?array $confirmTarget = null,
        public readonly ?array $contextFill = null,
    ) {}

    /**
     * @param list<string> $tables
     */
    public function withTouchedTables(array $tables): self
    {
        return $this->copyWith(touchedTables: $tables);
    }

    /**
     * @param null|array{ratio: float, level: string} $contextFill
     */
    public function withContextFill(?array $contextFill): self
    {
        return $this->copyWith(contextFill: $contextFill);
    }

    /**
     * @param list<array{id: string, name: string, arguments: array<string, mixed>, status?: string}>                   $toolCalls
     * @param array<string, mixed>                                                                                      $usage
     * @param null|array{summaryContent: string, replacedCount?: int}                                                   $historySummary
     * @param list<array{key: string, params?: array<string, int|string>}|string>                                       $notices
     * @param list<array{table: string, label: string, targets: list<array{label: string, url: string}>, omitted: int}> $navigationTargets
     * @param list<array{title: string, url: string, snippet: string}>                                                  $sources
     */
    public static function final(
        string $sessionUuid,
        string $text,
        array $toolCalls,
        array $usage,
        int $contextWindowTokens,
        ?array $historySummary = null,
        array $notices = [],
        array $navigationTargets = [],
        array $sources = [],
    ): self {
        return new self(
            status: self::STATUS_FINAL,
            sessionUuid: $sessionUuid,
            text: $text,
            toolCalls: $toolCalls,
            pending: [],
            usage: $usage,
            contextWindowTokens: $contextWindowTokens,
            historySummary: $historySummary,
            errorMessage: null,
            notices: $notices,
            sources: $sources,
            navigationTargets: $navigationTargets,
        );
    }

    /**
     * @param list<array{id: string, name: string, arguments: array<string, mixed>, status?: string}>                   $toolCalls
     * @param array<string, mixed>                                                                                      $usage
     * @param list<array{key: string, params?: array<string, int|string>}|string>                                       $notices
     * @param list<array{title: string, url: string, snippet: string}>                                                  $sources
     * @param list<array{table: string, label: string, targets: list<array{label: string, url: string}>, omitted: int}> $navigationTargets
     */
    public static function continuing(
        string $sessionUuid,
        string $text,
        array $toolCalls,
        array $usage,
        int $contextWindowTokens,
        array $notices = [],
        array $sources = [],
        array $navigationTargets = [],
    ): self {
        return new self(
            status: self::STATUS_CONTINUING,
            sessionUuid: $sessionUuid,
            text: $text,
            toolCalls: $toolCalls,
            pending: [],
            usage: $usage,
            contextWindowTokens: $contextWindowTokens,
            historySummary: null,
            errorMessage: null,
            notices: $notices,
            sources: $sources,
            navigationTargets: $navigationTargets,
        );
    }

    /**
     * @param list<array{id: string, name: string, arguments: array<string, mixed>, severity: string, preview?: null|array<string, mixed>}> $pending
     * @param array<string, mixed>                                                                                                          $usage
     * @param list<array{key: string, params?: array<string, int|string>}|string>                                                           $notices
     * @param list<array{id: string, name: string, arguments: array<string, mixed>, status?: string}>                                       $toolCalls
     * @param null|array{key: string, params?: array<string, string>}                                                                       $confirmTarget
     */
    public static function needsConfirm(
        string $sessionUuid,
        string $text,
        array $pending,
        array $usage,
        int $contextWindowTokens,
        array $notices = [],
        array $toolCalls = [],
        ?array $confirmTarget = null,
    ): self {
        return new self(
            status: self::STATUS_NEEDS_CONFIRM,
            sessionUuid: $sessionUuid,
            text: $text,
            toolCalls: $toolCalls,
            pending: $pending,
            usage: $usage,
            contextWindowTokens: $contextWindowTokens,
            historySummary: null,
            errorMessage: null,
            notices: $notices,
            confirmTarget: $confirmTarget,
        );
    }

    public static function error(string $sessionUuid, string $message, ?string $chatErrorCode = null): self
    {
        return new self(
            status: self::STATUS_ERROR,
            sessionUuid: $sessionUuid,
            text: '',
            toolCalls: [],
            pending: [],
            usage: null,
            contextWindowTokens: 0,
            historySummary: null,
            errorMessage: $message,
            chatErrorCode: $chatErrorCode,
        );
    }

    /**
     * @param array<string, mixed> $usage
     */
    public static function creditsExhausted(string $sessionUuid, array $usage, int $contextWindowTokens): self
    {
        return new self(
            status: self::STATUS_CREDITS_EXHAUSTED,
            sessionUuid: $sessionUuid,
            text: '',
            toolCalls: [],
            pending: [],
            usage: $usage,
            contextWindowTokens: $contextWindowTokens,
            historySummary: null,
            errorMessage: null,
        );
    }

    /**
     * @param null|array<string, mixed> $usage
     */
    public static function aborted(
        string $sessionUuid,
        string $reason,
        string $text,
        ?array $usage,
        int $contextWindowTokens,
    ): self {
        return new self(
            status: self::STATUS_ABORTED,
            sessionUuid: $sessionUuid,
            text: $text,
            toolCalls: [],
            pending: [],
            usage: $usage,
            contextWindowTokens: $contextWindowTokens,
            historySummary: null,
            errorMessage: null,
            abortReason: $reason,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'status' => $this->status,
            'sessionUuid' => $this->sessionUuid,
            'text' => $this->text,
            'toolCalls' => $this->toolCalls,
            'pending' => $this->pending,
            'contextWindowTokens' => $this->contextWindowTokens,
        ];
        if (null !== $this->usage) {
            $result['usage'] = $this->usage;
        }
        if (null !== $this->historySummary) {
            $result['historySummary'] = $this->historySummary;
        }
        if (null !== $this->errorMessage) {
            $result['error'] = ['message' => $this->errorMessage];
            if (null !== $this->chatErrorCode) {
                $result['error']['chatErrorCode'] = $this->chatErrorCode;
            }
        }
        if (null !== $this->abortReason) {
            $result['abortReason'] = $this->abortReason;
        }
        if ([] !== $this->touchedTables) {
            $result['touchedTables'] = $this->touchedTables;
        }
        if ([] !== $this->notices) {
            $result['notices'] = $this->notices;
        }
        if ([] !== $this->sources) {
            $result['sources'] = $this->sources;
        }
        if ([] !== $this->navigationTargets) {
            $result['navigationTargets'] = $this->navigationTargets;
        }
        if (null !== $this->confirmTarget) {
            $result['confirmTarget'] = $this->confirmTarget;
        }
        if (null !== $this->contextFill) {
            $result['contextFill'] = $this->contextFill;
        }

        return $result;
    }

    /**
     * @param null|list<string>                       $touchedTables
     * @param null|array{ratio: float, level: string} $contextFill
     */
    private function copyWith(?array $touchedTables = null, ?array $contextFill = null): self
    {
        return new self(
            status: $this->status,
            sessionUuid: $this->sessionUuid,
            text: $this->text,
            toolCalls: $this->toolCalls,
            pending: $this->pending,
            usage: $this->usage,
            contextWindowTokens: $this->contextWindowTokens,
            historySummary: $this->historySummary,
            errorMessage: $this->errorMessage,
            abortReason: $this->abortReason,
            notices: $this->notices,
            chatErrorCode: $this->chatErrorCode,
            sources: $this->sources,
            navigationTargets: $this->navigationTargets,
            touchedTables: $touchedTables ?? $this->touchedTables,
            confirmTarget: $this->confirmTarget,
            contextFill: $contextFill ?? $this->contextFill,
        );
    }
}
