<?php

namespace App\Support\Integrations\Monday\Dto;

final readonly class MondayCreatedItemResult
{
    public function __construct(
        public string $itemId,
        public string $boardId,
        public ?string $itemUrl,
        public bool $idempotencyReplayed = false,
        public ?string $providerRequestId = null,
        public string $apiVersion = '2026-07',
    ) {}
}
