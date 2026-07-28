<?php

use App\Support\Quotes\Acceptance\QuoteAcceptanceAtomicityContract;

test('acceptance atomicity contract documents the 2d2 step order without executing it', function () {
    expect(QuoteAcceptanceAtomicityContract::STEPS)->toBe([
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
    ])->and(QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE)->toBe('quote_revision.accepted');
});

test('acceptance atomicity contract designs stable idempotency keys and rejects unsafe payloads', function () {
    $contract = new QuoteAcceptanceAtomicityContract;

    expect($contract->designIdempotencyKey(42))
        ->toBe($contract->designIdempotencyKey(42))
        ->and($contract->designIdempotencyKey(42))
        ->toBe(hash('sha256', 'quote_revision.accepted:42'));

    $contract->assertOutboxPayloadIsSafe([
        'quote_revision_id' => 42,
        'quote_id' => 7,
        'organization_id' => 3,
        'document_id' => 9,
    ]);

    expect(fn () => $contract->assertOutboxPayloadIsSafe([
        'quote_revision_id' => 42,
        'access_token' => 'should-never-appear',
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => $contract->assertOutboxPayloadIsSafe([
        'quote_revision_id' => 42,
        'material_cost_micro_units' => 1,
    ]))->toThrow(InvalidArgumentException::class);
});
