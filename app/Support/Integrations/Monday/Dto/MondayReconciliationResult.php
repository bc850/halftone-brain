<?php

namespace App\Support\Integrations\Monday\Dto;

final readonly class MondayReconciliationResult
{
    public function __construct(
        public bool $found,
        public ?string $itemId = null,
        public ?string $boardId = null,
        public ?string $itemUrl = null,
        public ?string $integrationKey = null,
    ) {}
}
