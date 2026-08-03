<?php

namespace App\Support\Integrations\Outbox\Consumers;

use App\Enums\IntegrationConsumerOutcome;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationProviderReceiptDiscoveryMethod;
use App\Enums\IntegrationRemoteResourceType;
use App\Enums\IntegrationValidationStatus;
use App\Enums\MondayOutcomeClassification;
use App\Enums\QuoteRevisionStatus;
use App\Models\IntegrationOutbox;
use App\Models\IntegrationOutboxDelivery;
use App\Models\IntegrationProviderReceipt;
use App\Models\Organization;
use App\Models\OrganizationIntegrationSetting;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Support\Integrations\Monday\AcceptedQuoteMondayMapper;
use App\Support\Integrations\Monday\Credentials\MondayCredentialsProviderInterface;
use App\Support\Integrations\Monday\MondayApiClientException;
use App\Support\Integrations\Monday\MondayApiClientInterface;
use App\Support\Integrations\Monday\MondayApiVersion;
use App\Support\Integrations\Monday\MondayConsumerKeys;
use App\Support\Integrations\Monday\MondayIntakeReconciliationService;
use App\Support\Integrations\Monday\MondayProviderReceiptService;
use App\Support\Integrations\Outbox\IntegrationConsumerHandler;
use App\Support\Integrations\Outbox\IntegrationConsumerResult;
use App\Support\Quotes\Acceptance\QuoteAcceptanceAtomicityContract;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Dormant Monday intake consumer for isolated testing.
 *
 * Intentionally NOT registered in IntegrationConsumerRegistry until a later checkpoint.
 */
final class CreateMondayIntakeItemConsumer implements IntegrationConsumerHandler
{
    public function __construct(
        private MondayCredentialsProviderInterface $credentials,
        private MondayApiClientInterface $client,
        private AcceptedQuoteMondayMapper $mapper,
        private MondayIntakeReconciliationService $reconciliation,
        private MondayProviderReceiptService $receipts,
    ) {}

    public function consumerKey(): string
    {
        return MondayConsumerKeys::CREATE_INTAKE_ITEM;
    }

    public function supports(string $eventType, int $schemaVersion): bool
    {
        return $eventType === QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE
            && $schemaVersion === 1;
    }

    public function handle(IntegrationOutbox $outbox, IntegrationOutboxDelivery $delivery): IntegrationConsumerResult
    {
        if (! $this->supports($outbox->event_type, $outbox->schema_version)) {
            return IntegrationConsumerResult::permanent(
                'unsupported_schema_version',
                'Monday intake consumer does not support this event schema.',
            );
        }

        if (! TenantContext::has()) {
            return IntegrationConsumerResult::blockedConfiguration(
                'missing_tenant_context',
                'Tenant context is required for Monday intake.',
            );
        }

        $tenant = TenantContext::get();

        if (
            (int) $outbox->organization_id !== $tenant->organizationId
            || (int) $delivery->organization_id !== $tenant->organizationId
            || (int) $outbox->parent_account_id !== $tenant->parentAccountId
            || (int) $delivery->parent_account_id !== $tenant->parentAccountId
        ) {
            return IntegrationConsumerResult::permanent(
                'tenant_mismatch',
                'Outbox delivery tenant does not match the active tenant context.',
            );
        }

        $payload = $outbox->payload_json;
        if (! isset($payload['quote_id'], $payload['quote_revision_id'], $payload['organization_id'])) {
            return IntegrationConsumerResult::permanent(
                'invalid_payload',
                'Accepted quote payload is missing required identifiers.',
            );
        }

        if ((int) $payload['organization_id'] !== $tenant->organizationId) {
            return IntegrationConsumerResult::permanent(
                'tenant_mismatch',
                'Payload organization does not match the active tenant.',
            );
        }

        $existingReceipt = IntegrationProviderReceipt::query()
            ->where('integration_outbox_delivery_id', $delivery->id)
            ->where('provider', IntegrationProvider::Monday)
            ->where('remote_resource_type', IntegrationRemoteResourceType::Item)
            ->first();

        if ($existingReceipt !== null) {
            return IntegrationConsumerResult::succeeded([
                'provider' => IntegrationProvider::Monday->value,
                'resource_type' => IntegrationRemoteResourceType::Item->value,
                'remote_id' => $existingReceipt->remote_id,
                'remote_board_id' => $existingReceipt->remote_board_id,
                'remote_url' => $existingReceipt->remote_url,
                'idempotency_replayed' => $existingReceipt->idempotency_replayed,
                'api_version' => $existingReceipt->api_version,
                'idempotency_key' => $delivery->idempotency_key,
            ]);
        }

        $settings = OrganizationIntegrationSetting::query()
            ->where('organization_id', $tenant->organizationId)
            ->where('parent_account_id', $tenant->parentAccountId)
            ->where('provider', IntegrationProvider::Monday)
            ->first();

        if ($settings === null) {
            return IntegrationConsumerResult::blockedConfiguration(
                'settings_missing',
                'Monday settings are not configured for this organization.',
            );
        }

        if (! $settings->enabled) {
            return IntegrationConsumerResult::blockedConfiguration(
                'settings_disabled',
                'Monday settings are disabled for this organization.',
            );
        }

        if (
            $settings->last_validation_status !== IntegrationValidationStatus::Valid
            || $settings->last_validated_at === null
            || $settings->last_validation_error_code !== null
            || $settings->api_version !== MondayApiVersion::PINNED
            || trim((string) $settings->board_id) === ''
            || trim((string) $settings->group_id) === ''
        ) {
            return IntegrationConsumerResult::blockedConfiguration(
                'settings_invalid_or_stale',
                'Monday settings are not freshly validated for enablement.',
            );
        }

        if ($this->credentials->get() === null) {
            return IntegrationConsumerResult::blockedConfiguration(
                'client_not_configured',
                'Monday API credentials are not configured.',
            );
        }

        $organization = Organization::query()->whereKey($tenant->organizationId)->firstOrFail();
        $quote = Quote::query()
            ->whereKey((int) $payload['quote_id'])
            ->where('organization_id', $tenant->organizationId)
            ->first();
        $revision = QuoteRevision::query()
            ->whereKey((int) $payload['quote_revision_id'])
            ->where('organization_id', $tenant->organizationId)
            ->where('quote_id', (int) $payload['quote_id'])
            ->first();

        if ($quote === null || $revision === null) {
            return IntegrationConsumerResult::permanent(
                'quote_missing',
                'Accepted quote or revision could not be loaded for Monday intake.',
            );
        }

        if ($revision->status !== QuoteRevisionStatus::Accepted) {
            return IntegrationConsumerResult::permanent(
                'revision_not_accepted',
                'Quote revision is not in the accepted state.',
            );
        }

        $revision->loadMissing(['partySnapshot', 'lineItems', 'currentTaxCalculation']);

        try {
            $request = $this->mapper->map(
                quote: $quote,
                revision: $revision,
                organization: $organization,
                settings: $settings,
                party: $revision->partySnapshot,
                tax: $revision->currentTaxCalculation,
                deliveryIdempotencyKey: $delivery->idempotency_key,
            );
        } catch (Throwable $exception) {
            return IntegrationConsumerResult::blockedConfiguration(
                'mapping_failed',
                'Unable to map the accepted quote for Monday intake.',
            );
        }

        // Prefer reconcile when a prior uncertain outcome may have created the item.
        if ($delivery->attempt_count > 0) {
            try {
                $reconciled = $this->reconciliation->reconcile(
                    delivery: $delivery,
                    settings: $settings,
                    integrationKey: $request->integrationKey,
                    correlationId: $outbox->correlation_id,
                );

                if ($reconciled->outcome === IntegrationConsumerOutcome::Succeeded) {
                    return $reconciled;
                }

                if ($reconciled->outcome === IntegrationConsumerOutcome::PermanentFailure) {
                    return $reconciled;
                }
            } catch (MondayApiClientException $exception) {
                return $this->mapClientException($exception);
            }
        }

        // HTTP create must remain outside any DB transaction.
        if (DB::transactionLevel() > 0) {
            return IntegrationConsumerResult::permanent(
                'transaction_boundary_violation',
                'Monday create cannot run inside an open database transaction.',
            );
        }

        try {
            $created = $this->client->createIntakeItem($request);
        } catch (MondayApiClientException $exception) {
            if ($exception->error->classification === MondayOutcomeClassification::UncertainOutcome) {
                try {
                    return $this->reconciliation->reconcile(
                        delivery: $delivery,
                        settings: $settings,
                        integrationKey: $request->integrationKey,
                        correlationId: $outbox->correlation_id,
                    );
                } catch (MondayApiClientException $reconcileException) {
                    return $this->mapClientException($reconcileException);
                }
            }

            return $this->mapClientException($exception);
        }

        $discovery = $created->idempotencyReplayed
            ? IntegrationProviderReceiptDiscoveryMethod::IdempotencyReplay
            : IntegrationProviderReceiptDiscoveryMethod::Created;

        $receipt = $this->receipts->linkCreatedItem(
            delivery: $delivery,
            result: $created,
            discoveryMethod: $discovery,
            correlationId: $outbox->correlation_id,
        );

        return IntegrationConsumerResult::succeeded([
            'provider' => IntegrationProvider::Monday->value,
            'resource_type' => IntegrationRemoteResourceType::Item->value,
            'remote_id' => $receipt->remote_id,
            'remote_board_id' => $receipt->remote_board_id,
            'remote_url' => $receipt->remote_url,
            'provider_request_id' => $receipt->provider_request_id,
            'idempotency_replayed' => $receipt->idempotency_replayed,
            'api_version' => $receipt->api_version,
            'idempotency_key' => $delivery->idempotency_key,
        ]);
    }

    private function mapClientException(MondayApiClientException $exception): IntegrationConsumerResult
    {
        $error = $exception->error;

        return match ($error->classification) {
            MondayOutcomeClassification::RateLimited => IntegrationConsumerResult::retryable(
                $error->code,
                $error->message,
            ),
            MondayOutcomeClassification::Retryable => IntegrationConsumerResult::retryable(
                $error->code,
                $error->message,
            ),
            MondayOutcomeClassification::BlockedConfiguration => IntegrationConsumerResult::blockedConfiguration(
                $error->code,
                $error->message,
            ),
            MondayOutcomeClassification::UncertainOutcome => IntegrationConsumerResult::uncertain(
                $error->code,
                $error->message,
            ),
            MondayOutcomeClassification::PermanentFailure, MondayOutcomeClassification::Success => IntegrationConsumerResult::permanent(
                $error->code,
                $error->message,
            ),
        };
    }
}
