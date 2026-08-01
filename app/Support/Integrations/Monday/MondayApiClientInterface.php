<?php

namespace App\Support\Integrations\Monday;

use App\Support\Integrations\Monday\Dto\MondayBoardMetadata;
use App\Support\Integrations\Monday\Dto\MondayCreatedItemResult;
use App\Support\Integrations\Monday\Dto\MondayCreateItemRequest;
use App\Support\Integrations\Monday\Dto\MondayReconciliationResult;

/**
 * Provider client contract for Monday intake operations.
 *
 * Implementations must not expose raw HTTP responses or accept API tokens through
 * method parameters. Production auth belongs in environment configuration only.
 */
interface MondayApiClientInterface
{
    /**
     * Inspect board schema (groups + columns) for configuration validation.
     *
     * @throws MondayApiClientException
     */
    public function inspectBoard(string $boardId): MondayBoardMetadata;

    /**
     * Create an intake item (or return an idempotent replay of an existing item).
     *
     * @throws MondayApiClientException
     */
    public function createIntakeItem(MondayCreateItemRequest $request): MondayCreatedItemResult;

    /**
     * Find an existing item by the Halftone integration key text column.
     *
     * @throws MondayApiClientException
     */
    public function findItemByIntegrationKey(string $boardId, string $integrationKeyColumnId, string $integrationKey): MondayReconciliationResult;
}
