<?php

namespace App\Support\Integrations\Monday\Dto;

/**
 * Bounded reconciliation lookup against the dedicated integration-key text column.
 */
final readonly class MondayReconciliationResult
{
    public function __construct(
        public bool $found,
        public ?string $itemId = null,
        public ?string $boardId = null,
        public ?string $itemUrl = null,
        public ?string $integrationKey = null,
        public bool $ambiguous = false,
        public int $matchCount = 0,
    ) {}

    public static function none(string $integrationKey = ''): self
    {
        return new self(
            found: false,
            integrationKey: $integrationKey !== '' ? $integrationKey : null,
            matchCount: 0,
        );
    }

    public static function one(
        string $itemId,
        string $boardId,
        ?string $itemUrl,
        string $integrationKey,
    ): self {
        return new self(
            found: true,
            itemId: $itemId,
            boardId: $boardId,
            itemUrl: $itemUrl,
            integrationKey: $integrationKey,
            matchCount: 1,
        );
    }

    public static function ambiguous(int $matchCount, string $integrationKey = ''): self
    {
        return new self(
            found: false,
            integrationKey: $integrationKey !== '' ? $integrationKey : null,
            ambiguous: true,
            matchCount: max(2, $matchCount),
        );
    }
}
