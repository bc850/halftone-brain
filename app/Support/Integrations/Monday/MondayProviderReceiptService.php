<?php

namespace App\Support\Integrations\Monday;

use App\Enums\IntegrationProvider;
use App\Enums\IntegrationProviderReceiptDiscoveryMethod;
use App\Enums\IntegrationRemoteResourceType;
use App\Models\IntegrationOutboxDelivery;
use App\Models\IntegrationProviderReceipt;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Integrations\Monday\Dto\MondayCreatedItemResult;
use App\Support\Integrations\Outbox\IntegrationErrorSanitizer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Transactional append-only Monday provider receipt writer.
 */
final class MondayProviderReceiptService
{
    public const AUDIT_ITEM_LINKED = 'integrations.monday.item_linked';

    public function __construct(
        private Auditor $auditor,
        private IntegrationErrorSanitizer $sanitizer,
    ) {}

    public function linkCreatedItem(
        IntegrationOutboxDelivery $delivery,
        MondayCreatedItemResult $result,
        IntegrationProviderReceiptDiscoveryMethod $discoveryMethod,
        ?User $actor = null,
        ?string $correlationId = null,
    ): IntegrationProviderReceipt {
        if (! TenantContext::has()) {
            throw new InvalidArgumentException('Tenant context is required.');
        }

        $tenant = TenantContext::get();

        if (
            (int) $delivery->organization_id !== $tenant->organizationId
            || (int) $delivery->parent_account_id !== $tenant->parentAccountId
        ) {
            throw new InvalidArgumentException('Delivery does not match the current tenant.');
        }

        return DB::transaction(function () use ($delivery, $result, $discoveryMethod, $actor, $correlationId, $tenant): IntegrationProviderReceipt {
            $existing = IntegrationProviderReceipt::query()
                ->where('integration_outbox_delivery_id', $delivery->id)
                ->where('provider', IntegrationProvider::Monday)
                ->where('remote_resource_type', IntegrationRemoteResourceType::Item)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->remote_id !== $result->itemId) {
                    throw new InvalidArgumentException('Conflicting Monday remote item identity for delivery receipt.');
                }

                $this->updateDeliveryReference($delivery, $result, $discoveryMethod);

                return $existing;
            }

            try {
                $receipt = IntegrationProviderReceipt::query()->create([
                    'parent_account_id' => $tenant->parentAccountId,
                    'organization_id' => $tenant->organizationId,
                    'integration_outbox_delivery_id' => $delivery->id,
                    'provider' => IntegrationProvider::Monday,
                    'remote_resource_type' => IntegrationRemoteResourceType::Item,
                    'remote_id' => $result->itemId,
                    'remote_board_id' => $result->boardId,
                    'remote_url' => $result->itemUrl,
                    'provider_request_id' => $result->providerRequestId,
                    'idempotency_replayed' => $result->idempotencyReplayed,
                    'api_version' => $result->apiVersion,
                    'linked_at' => now(),
                    'discovery_method' => $discoveryMethod,
                ]);
            } catch (QueryException $exception) {
                $existingAfterRace = IntegrationProviderReceipt::query()
                    ->where('integration_outbox_delivery_id', $delivery->id)
                    ->where('provider', IntegrationProvider::Monday)
                    ->where('remote_resource_type', IntegrationRemoteResourceType::Item)
                    ->first();

                if ($existingAfterRace !== null && $existingAfterRace->remote_id === $result->itemId) {
                    $this->updateDeliveryReference($delivery, $result, $discoveryMethod);

                    return $existingAfterRace;
                }

                throw $exception;
            }

            $this->updateDeliveryReference($delivery, $result, $discoveryMethod);

            $this->auditor->append(
                parentAccount: $receipt->parentAccount,
                action: self::AUDIT_ITEM_LINKED,
                subjectType: IntegrationProviderReceipt::class,
                subjectId: $receipt->id,
                organization: $receipt->organization,
                actor: $actor,
                before: null,
                after: [
                    'delivery_id' => $delivery->id,
                    'outbox_id' => $delivery->integration_outbox_id,
                    'remote_id' => $result->itemId,
                    'remote_board_id' => $result->boardId,
                    'remote_url' => $result->itemUrl,
                    'discovery_method' => $discoveryMethod->value,
                    'idempotency_replayed' => $result->idempotencyReplayed,
                    'api_version' => $result->apiVersion,
                    'correlation_id' => $correlationId ?? (string) Str::uuid(),
                ],
                correlationId: $correlationId ?? (string) Str::uuid(),
            );

            return $receipt;
        });
    }

    private function updateDeliveryReference(
        IntegrationOutboxDelivery $delivery,
        MondayCreatedItemResult $result,
        IntegrationProviderReceiptDiscoveryMethod $discoveryMethod,
    ): void {
        $reference = $this->sanitizer->providerReference([
            'provider' => IntegrationProvider::Monday->value,
            'resource_type' => IntegrationRemoteResourceType::Item->value,
            'remote_id' => $result->itemId,
            'remote_board_id' => $result->boardId,
            'remote_url' => $result->itemUrl,
            'provider_request_id' => $result->providerRequestId,
            'idempotency_replayed' => $result->idempotencyReplayed,
            'api_version' => $result->apiVersion,
            'idempotency_key' => $delivery->idempotency_key,
            'discovery_method' => $discoveryMethod->value,
        ]);

        // discovery_method is not in allowlist — strip via sanitizer already.
        $delivery->forceFill([
            'provider_reference_json' => $reference,
        ])->save();
    }
}
