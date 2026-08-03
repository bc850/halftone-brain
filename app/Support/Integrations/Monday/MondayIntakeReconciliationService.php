<?php

namespace App\Support\Integrations\Monday;

use App\Enums\IntegrationProvider;
use App\Enums\IntegrationProviderReceiptDiscoveryMethod;
use App\Enums\IntegrationRemoteResourceType;
use App\Enums\MondayIntakeLogicalKey;
use App\Models\IntegrationOutboxDelivery;
use App\Models\IntegrationProviderReceipt;
use App\Models\OrganizationIntegrationSetting;
use App\Models\User;
use App\Support\Integrations\Monday\Dto\MondayCreatedItemResult;
use App\Support\Integrations\Outbox\IntegrationConsumerResult;
use App\Support\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Reconciles uncertain Monday create outcomes without treating the temporary
 * Idempotency-Key cache as permanent truth.
 */
final class MondayIntakeReconciliationService
{
    public function __construct(
        private MondayApiClientInterface $client,
        private MondayProviderReceiptService $receipts,
    ) {}

    public function reconcile(
        IntegrationOutboxDelivery $delivery,
        OrganizationIntegrationSetting $settings,
        string $integrationKey,
        ?User $actor = null,
        ?string $correlationId = null,
    ): IntegrationConsumerResult {
        if (! TenantContext::has()) {
            throw new InvalidArgumentException('Tenant context is required.');
        }

        $existing = IntegrationProviderReceipt::query()
            ->where('integration_outbox_delivery_id', $delivery->id)
            ->where('provider', IntegrationProvider::Monday)
            ->where('remote_resource_type', IntegrationRemoteResourceType::Item)
            ->first();

        if ($existing !== null) {
            return IntegrationConsumerResult::succeeded([
                'provider' => IntegrationProvider::Monday->value,
                'resource_type' => IntegrationRemoteResourceType::Item->value,
                'remote_id' => $existing->remote_id,
                'remote_board_id' => $existing->remote_board_id,
                'remote_url' => $existing->remote_url,
                'provider_request_id' => $existing->provider_request_id,
                'idempotency_replayed' => $existing->idempotency_replayed,
                'api_version' => $existing->api_version,
                'idempotency_key' => $delivery->idempotency_key,
            ]);
        }

        $mapping = MondayColumnMappingSet::fromArray($settings->column_mapping_json);
        $integrationEntry = $mapping->get(MondayIntakeLogicalKey::IntegrationKey);

        if ($integrationEntry === null || ! $integrationEntry->enabled) {
            return IntegrationConsumerResult::blockedConfiguration(
                'missing_integration_key_mapping',
                'Integration key column mapping is required for reconciliation.',
            );
        }

        $lookup = $this->client->findItemByIntegrationKey(
            boardId: (string) $settings->board_id,
            integrationKeyColumnId: $integrationEntry->columnId,
            integrationKey: $integrationKey,
        );

        if ($lookup->ambiguous) {
            return IntegrationConsumerResult::permanent(
                'ambiguous_integration_key',
                'Multiple Monday items matched the integration key. Operations escalation required.',
            );
        }

        if (! $lookup->found || $lookup->itemId === null) {
            return IntegrationConsumerResult::retryable(
                'not_found_for_reconciliation',
                'No Monday item found for integration key; retry create with the same idempotency key.',
            );
        }

        $result = new MondayCreatedItemResult(
            itemId: $lookup->itemId,
            boardId: $lookup->boardId ?? (string) $settings->board_id,
            itemUrl: $lookup->itemUrl,
            idempotencyReplayed: false,
            providerRequestId: null,
            apiVersion: $settings->api_version,
        );

        $receipt = $this->receipts->linkCreatedItem(
            delivery: $delivery,
            result: $result,
            discoveryMethod: IntegrationProviderReceiptDiscoveryMethod::Reconciled,
            actor: $actor,
            correlationId: $correlationId,
        );

        return IntegrationConsumerResult::succeeded([
            'provider' => IntegrationProvider::Monday->value,
            'resource_type' => IntegrationRemoteResourceType::Item->value,
            'remote_id' => $receipt->remote_id,
            'remote_board_id' => $receipt->remote_board_id,
            'remote_url' => $receipt->remote_url,
            'idempotency_replayed' => false,
            'api_version' => $receipt->api_version,
            'idempotency_key' => $delivery->idempotency_key,
        ]);
    }
}
