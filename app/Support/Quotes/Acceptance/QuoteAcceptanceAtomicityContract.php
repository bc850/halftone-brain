<?php

namespace App\Support\Quotes\Acceptance;

use App\Support\Quotes\Snapshots\CustomerSafeQuoteProjection;
use InvalidArgumentException;

/**
 * Documents the Phase 2D.2 acceptance transaction contract without executing it.
 *
 * Repeated acceptance must not create duplicate response events or outbox rows.
 * External side effects (email, integrations, PDF) happen only after commit.
 */
final class QuoteAcceptanceAtomicityContract
{
    /**
     * Ordered steps a future acceptance service must perform inside one DB transaction.
     *
     * @var list<string>
     */
    public const STEPS = [
        'lock_quote_revision_document_token',
        'validate_token_and_immutable_document',
        'validate_approved_and_sent_state',
        'append_customer_response_event',
        'transition_revision_accepted',
        'set_quote_accepted_revision_and_lifecycle',
        'move_deal_to_quote_won',
        'revoke_other_active_tokens',
        'append_audit_and_status_events',
        'insert_quote_revision_accepted_outbox_row',
        'commit',
        'external_work_after_commit',
    ];

    public const ACCEPTED_EVENT_TYPE = 'quote_revision.accepted';

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidArgumentException when the payload contains forbidden or secret keys
     */
    public function assertOutboxPayloadIsSafe(array $payload): void
    {
        $forbidden = array_merge(
            CustomerSafeQuoteProjection::forbiddenKeys(),
            [
                'token',
                'raw_token',
                'access_token',
                'customer_access_token',
                'token_hash',
                'password',
                'secret',
                'credential',
                'credentials',
                'api_key',
                'certificate_number',
                'private_html_path',
                'private_pdf_path',
                'customer_payload_snapshot_json',
                'ip_address',
                'ip_address_encrypted',
            ],
        );

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        foreach ($forbidden as $key) {
            if (str_contains($encoded, '"'.$key.'"')) {
                throw new InvalidArgumentException("Outbox payload must not contain forbidden key [{$key}].");
            }
        }
    }

    /**
     * Deterministic idempotency key for a single accepted outbox row per revision.
     */
    public function designIdempotencyKey(int $quoteRevisionId, string $eventType = self::ACCEPTED_EVENT_TYPE): string
    {
        return hash('sha256', $eventType.':'.$quoteRevisionId);
    }
}
