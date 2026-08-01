<?php

namespace App\Support\Integrations\Outbox\Consumers;

use App\Models\IntegrationOutbox;
use App\Models\IntegrationOutboxDelivery;
use App\Support\Integrations\Outbox\IntegrationConsumerHandler;
use App\Support\Integrations\Outbox\IntegrationConsumerResult;
use App\Support\Quotes\Acceptance\QuoteAcceptanceAtomicityContract;

/**
 * No-external-call diagnostic consumer proving fan-out/materialization wiring.
 */
final class DiagnosticAcceptedQuoteProbeConsumer implements IntegrationConsumerHandler
{
    public const CONSUMER_KEY = 'integrations.diagnostic.accepted_quote_probe';

    public function consumerKey(): string
    {
        return self::CONSUMER_KEY;
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
                'Diagnostic probe does not support this event schema.',
            );
        }

        $payload = $outbox->payload_json;

        if (! isset($payload['quote_revision_id'], $payload['quote_id'], $payload['organization_id'])) {
            return IntegrationConsumerResult::permanent(
                'invalid_payload',
                'Accepted quote probe requires quote_revision_id, quote_id, and organization_id.',
            );
        }

        return IntegrationConsumerResult::succeeded([
            'provider' => 'diagnostic',
            'resource_type' => 'probe',
            'remote_id' => 'probe:'.$outbox->id.':'.$delivery->id,
            'idempotency_key' => $delivery->idempotency_key,
        ]);
    }
}
