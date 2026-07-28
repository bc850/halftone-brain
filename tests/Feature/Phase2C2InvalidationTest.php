<?php

use App\Enums\QuoteAdjustmentMethod;
use App\Enums\QuoteAdjustmentType;
use App\Enums\QuoteApprovalRequestStatus;
use App\Enums\QuoteRevisionStatus;
use App\Enums\QuoteTaxCalculationStatus;
use App\Models\AuditEvent;
use App\Models\OrganizationCompany;
use App\Models\OrganizationProduct;
use App\Models\Quote;
use App\Models\QuoteApprovalRequest;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionTaxCalculation;
use App\Support\Quotes\Approval\QuoteApprovalWorkflowService;
use App\Support\Quotes\QuoteDraftAdjustmentService;
use App\Support\Quotes\QuoteDraftLineService;
use App\Support\Quotes\QuotePartySnapshotService;
use App\Support\Quotes\QuoteRepriceService;
use App\Support\Quotes\QuoteRevisionCloner;
use App\Support\Quotes\Tax\QuoteTaxCalculationService;
use Tests\Support\Phase2C2Fixture;

/**
 * A draft quote whose tax is already resolved at 8% of a $1,000 line.
 *
 * @return array{ctx: array<string, mixed>, quote: Quote, revision: QuoteRevision, organizationCompany: OrganizationCompany}
 */
function phase2c2TaxedDraft(int $lineUnitPriceCents = 100_000): array
{
    $fixture = Phase2C2Fixture::draftQuote(lineUnitPriceCents: $lineUnitPriceCents);
    $ctx = $fixture['ctx'];

    Phase2C2Fixture::taxProfile($ctx);
    $rate = Phase2C2Fixture::taxRate($ctx);

    app(QuoteTaxCalculationService::class)->calculate(
        quote: $fixture['quote'],
        revision: $fixture['revision'],
        expectedLockVersion: $fixture['revision']->lock_version,
        organizationTaxRateId: $rate->id,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    return [
        'ctx' => $ctx,
        'quote' => $fixture['quote']->fresh(),
        'revision' => $fixture['revision']->fresh(),
        'organizationCompany' => $fixture['organizationCompany'],
    ];
}

test('adding a line invalidates tax, keeps its history, and bumps the lock exactly once', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = phase2c2TaxedDraft();

    expect($revision->tax_cents)->toBe(8_000)
        ->and($revision->grand_total_cents)->toBe(108_000);

    $calculationId = $revision->current_tax_calculation_id;
    $lockVersionBefore = $revision->lock_version;

    Phase2C2Fixture::addTaxableLine($ctx, $quote, $revision, 50_000);

    $revision = $revision->fresh();

    expect($revision->tax_calculation_status)->toBe(QuoteTaxCalculationStatus::Pending)
        ->and($revision->tax_cents)->toBe(0)
        ->and($revision->current_tax_calculation_id)->toBeNull()
        ->and($revision->tax_snapshot_json)->toBeNull()
        ->and($revision->tax_calculated_at)->toBeNull()
        ->and($revision->subtotal_cents)->toBe(150_000)
        ->and($revision->grand_total_cents)->toBe(150_000)
        // One user action, one lock bump: the invalidation rides along with the mutation.
        ->and($revision->lock_version)->toBe($lockVersionBefore + 1);

    // The calculation that was relied on is still readable.
    expect(QuoteRevisionTaxCalculation::query()->whereKey($calculationId)->exists())->toBeTrue();

    $lineAudit = AuditEvent::query()->where('action', 'crm.quote.line_added')->latest('id')->firstOrFail();
    $invalidation = AuditEvent::query()->where('action', 'crm.quote.tax_invalidated')->sole();

    expect($invalidation->correlation_id)->toBe($lineAudit->correlation_id)
        ->and($invalidation->before_json['tax_cents'])->toBe(8_000)
        ->and($invalidation->before_json['grand_total_cents'])->toBe(108_000)
        ->and($invalidation->after_json['tax_cents'])->toBe(0)
        ->and($invalidation->after_json['grand_total_cents'])->toBe(100_000);
});

test('every draft money mutation invalidates the resolved tax', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = phase2c2TaxedDraft();

    $line = $revision->lineItems()->firstOrFail();

    app(QuoteDraftLineService::class)->updateLine(
        quote: $quote,
        revision: $revision,
        expectedRevisionLockVersion: $revision->lock_version,
        line: $line,
        data: ['quantity' => '3'],
        actor: $ctx['user'],
    );

    expect($revision->fresh()->tax_calculation_status)->toBe(QuoteTaxCalculationStatus::Pending);

    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = phase2c2TaxedDraft();

    app(QuoteDraftAdjustmentService::class)->add(
        quote: $quote,
        revision: $revision,
        expectedRevisionLockVersion: $revision->lock_version,
        adjustmentType: QuoteAdjustmentType::Shipping,
        description: 'Freight',
        method: QuoteAdjustmentMethod::Fixed,
        inputValue: 2_500,
        isTaxable: true,
        actor: $ctx['user'],
    );

    $revision = $revision->fresh();

    expect($revision->tax_calculation_status)->toBe(QuoteTaxCalculationStatus::Pending)
        ->and($revision->tax_cents)->toBe(0)
        ->and($revision->grand_total_cents)->toBe(102_500);

    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = phase2c2TaxedDraft();

    $line = $revision->lineItems()->firstOrFail();
    OrganizationProduct::query()
        ->whereKey($line->organization_product_id)
        ->update(['fixed_price_cents' => 120_000, 'pricing_version' => 2]);

    app(QuoteRepriceService::class)->repriceLine(
        $quote,
        $revision,
        $revision->lock_version,
        $line,
        true,
        $ctx['user'],
    );

    $revision = $revision->fresh();

    expect($revision->tax_calculation_status)->toBe(QuoteTaxCalculationStatus::Pending)
        ->and($revision->tax_cents)->toBe(0)
        ->and($revision->grand_total_cents)->toBe(120_000);
});

test('editing customer-visible content invalidates tax but an internal note does not', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = phase2c2TaxedDraft();

    $snapshots = app(QuotePartySnapshotService::class);

    $afterInternalNote = $snapshots->updateRevisionContent(
        quote: $quote,
        revision: $revision,
        expectedLockVersion: $revision->lock_version,
        data: ['internal_notes' => 'Chase the shop for lead time'],
        actor: $ctx['user'],
    );

    expect($afterInternalNote->tax_calculation_status)->toBe(QuoteTaxCalculationStatus::Calculated)
        ->and($afterInternalNote->tax_cents)->toBe(8_000)
        ->and($afterInternalNote->grand_total_cents)->toBe(108_000);

    $afterCustomerNote = $snapshots->updateRevisionContent(
        quote: $quote->fresh(),
        revision: $afterInternalNote,
        expectedLockVersion: $afterInternalNote->lock_version,
        data: ['customer_notes' => 'Includes weekend installation'],
        actor: $ctx['user'],
    );

    expect($afterCustomerNote->tax_calculation_status)->toBe(QuoteTaxCalculationStatus::Pending)
        ->and($afterCustomerNote->tax_cents)->toBe(0)
        ->and($afterCustomerNote->grand_total_cents)->toBe(100_000);
});

test('editing the party snapshot of a taxed draft invalidates the tax position', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = phase2c2TaxedDraft();

    app(QuotePartySnapshotService::class)->updateDraft(
        quote: $quote,
        revision: $revision,
        expectedLockVersion: $revision->lock_version,
        data: ['service_address_json' => [
            'line1' => '17 Peachtree St',
            'city' => 'Atlanta',
            'state' => 'GA',
            'postal_code' => '30303',
        ]],
        actor: $ctx['user'],
    );

    expect($revision->fresh()->tax_calculation_status)->toBe(QuoteTaxCalculationStatus::Pending)
        ->and($revision->fresh()->grand_total_cents)->toBe(100_000);
});

test('a mutation on an approved draft supersedes the pending approval it invalidates', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = phase2c2TaxedDraft(200_000);

    $workflow = app(QuoteApprovalWorkflowService::class);

    $submitted = $workflow->submitForApproval(
        quote: $quote,
        revision: $revision,
        expectedQuoteLockVersion: $quote->lock_version,
        expectedRevisionLockVersion: $revision->lock_version,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    $request = QuoteApprovalRequest::query()->sole();
    $quote = $quote->fresh();

    $reopened = $workflow->withdrawToDraft(
        quote: $quote,
        revision: $submitted,
        expectedQuoteLockVersion: $quote->lock_version,
        expectedRevisionLockVersion: $submitted->lock_version,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    // Reopen a second request so the mutation has an open request to supersede.
    $resubmitted = $workflow->submitForApproval(
        quote: $quote->fresh(),
        revision: $reopened,
        expectedQuoteLockVersion: $quote->fresh()->lock_version,
        expectedRevisionLockVersion: $reopened->lock_version,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    $secondRequest = QuoteApprovalRequest::query()->whereKeyNot($request->id)->sole();

    $backToDraft = $workflow->withdrawToDraft(
        quote: $quote->fresh(),
        revision: $resubmitted,
        expectedQuoteLockVersion: $quote->fresh()->lock_version,
        expectedRevisionLockVersion: $resubmitted->lock_version,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    expect($secondRequest->fresh()->status)->toBe(QuoteApprovalRequestStatus::Cancelled)
        ->and($backToDraft->status)->toBe(QuoteRevisionStatus::Draft)
        ->and($backToDraft->current_approval_request_id)->toBeNull();
});

test('cloning a taxed revision starts the new draft with an unresolved tax position', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = phase2c2TaxedDraft();

    $clone = app(QuoteRevisionCloner::class)->cloneToDraft(
        quote: $quote,
        source: $revision,
        expectedQuoteLockVersion: $quote->lock_version,
        actor: $ctx['user'],
    );

    expect($clone->tax_calculation_status)->toBe(QuoteTaxCalculationStatus::Pending)
        ->and($clone->tax_cents)->toBe(0)
        ->and($clone->tax_snapshot_json)->toBeNull()
        ->and($clone->tax_calculated_at)->toBeNull()
        ->and($clone->current_tax_calculation_id)->toBeNull()
        ->and($clone->current_approval_request_id)->toBeNull()
        ->and($clone->grand_total_cents)->toBe(100_000)
        // The source revision keeps the figure it was carrying.
        ->and($revision->fresh()->tax_cents)->toBe(8_000)
        ->and(QuoteRevisionTaxCalculation::query()->where('quote_revision_id', $clone->id)->count())->toBe(0);
});
