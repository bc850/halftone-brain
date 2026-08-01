<?php

namespace App\Support\Integrations\Monday\Dto;

use App\Support\Integrations\Monday\MondaySensitivePayloadGuard;

/**
 * Sanitized create-item request. Must never carry tokens or internal secrets.
 *
 * @phpstan-type ColumnValueMap array<string, string|int|float|bool|null>
 */
final readonly class MondayCreateItemRequest
{
    /**
     * @param  ColumnValueMap  $columnValues
     */
    public function __construct(
        public string $boardId,
        public ?string $groupId,
        public string $itemName,
        public string $integrationKey,
        public array $columnValues,
        public string $apiVersion = '2026-07',
    ) {
        MondaySensitivePayloadGuard::assertNoSensitiveKeys([
            'board_id' => $this->boardId,
            'group_id' => $this->groupId,
            'item_name' => $this->itemName,
            'integration_key' => $this->integrationKey,
            'column_values' => $this->columnValues,
            'api_version' => $this->apiVersion,
        ]);
    }
}
