<?php

namespace App\Support\Integrations\Monday\Dto;

/**
 * Board schema metadata returned by client contracts (no raw HTTP payload).
 */
final readonly class MondayBoardMetadata
{
    /**
     * @param  list<MondayGroupMetadata>  $groups
     * @param  list<MondayColumnMetadata>  $columns
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $groups,
        public array $columns,
    ) {}
}
