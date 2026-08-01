<?php

namespace App\Support\Integrations\Monday\Dto;

final readonly class MondayGroupMetadata
{
    public function __construct(
        public string $id,
        public string $title,
    ) {}
}
