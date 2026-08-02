<?php

namespace App\Support\Integrations\Monday\Dto;

use App\Enums\MondayColumnType;

final readonly class MondayColumnMetadata
{
    /**
     * @param  list<string>  $statusLabels
     */
    public function __construct(
        public string $id,
        public string $title,
        public MondayColumnType $type,
        public array $statusLabels = [],
    ) {}
}
