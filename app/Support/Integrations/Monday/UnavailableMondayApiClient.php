<?php

namespace App\Support\Integrations\Monday;

use App\Support\Integrations\Monday\Dto\MondayBoardMetadata;
use App\Support\Integrations\Monday\Dto\MondayCreatedItemResult;
use App\Support\Integrations\Monday\Dto\MondayCreateItemRequest;
use App\Support\Integrations\Monday\Dto\MondayReconciliationResult;

/**
 * Fail-closed client used when Monday credentials are unavailable.
 */
final class UnavailableMondayApiClient implements MondayApiClientInterface
{
    public function inspectBoard(string $boardId): MondayBoardMetadata
    {
        throw MondayApiClientException::clientNotConfigured();
    }

    public function createIntakeItem(MondayCreateItemRequest $request): MondayCreatedItemResult
    {
        throw MondayApiClientException::clientNotConfigured();
    }

    public function findItemByIntegrationKey(
        string $boardId,
        string $integrationKeyColumnId,
        string $integrationKey,
    ): MondayReconciliationResult {
        throw MondayApiClientException::clientNotConfigured();
    }
}
