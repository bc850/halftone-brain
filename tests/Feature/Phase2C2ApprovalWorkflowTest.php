<?php

use App\Enums\QuoteApprovalDecisionType;
use App\Enums\QuoteApprovalRequestStatus;
use App\Enums\QuoteRevisionStatus;
use App\Enums\QuoteStatusTransitionSource;
use App\Enums\QuoteTaxCalculationStatus;
use App\Models\AuditEvent;
use App\Models\Quote;
use App\Models\QuoteApprovalDecision;
use App\Models\QuoteApprovalRequest;
use App\Models\QuoteRevision;
use App\Models\QuoteStatusEvent;
use App\Support\Quotes\Approval\InvalidQuoteApprovalException;
use App\Support\Quotes\Approval\QuoteApprovalWorkflowService;
use App\Support\Quotes\StaleQuoteStateException;
use App\Support\Quotes\Tax\QuoteTaxCalculationService;
use Tests\Support\Phase2C2Fixture;

/**
 * @param  array<string, mixed>  $ctx
 */
function phase2c2ResolveTax(array $ctx, Quote $quote, QuoteRevision $revision): QuoteRevision
{
    Phase2C2Fixture::taxProfile($ctx);
    $rate = Phase2C2Fixture::taxRate($ctx);

    app(QuoteTaxCalculationService::class)->calculate(
        quote: $quote->fresh(),
        revision: $revision->fresh(),
        expectedLockVersion: $revision->fresh()->lock_version,
        organizationTaxRateId: $rate->id,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    return $revision->fresh();
}

test('approval cannot be requested while the tax position is unresolved', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = Phase2C2Fixture::draftQuote();

    expect($revision->tax_calculation_status)->toBe(QuoteTaxCalculationStatus::Pending);

    expect(fn () => app(QuoteApprovalWorkflowService::class)->submitForApproval(
        quote: $quote,
        revision: $revision,
        expectedQuoteLockVersion: $quote->lock_version,
        expectedRevisionLockVersion: $revision->lock_version,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    ))->toThrow(InvalidQuoteApprovalException::class);

    expect(QuoteApprovalRequest::query()->count())->toBe(0)
        ->and($revision->fresh()->status)->toBe(QuoteRevisionStatus::Draft);
});

test('a quote one cent over the threshold needs approval and is granted by a decision', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision, 'organizationCompany' => $company] =
        Phase2C2Fixture::draftQuote(lineUnitPriceCents: 150_001);

    Phase2C2Fixture::makeCustomerEstablished($company);
    $revision = phase2c2ResolveTax($ctx, $quote, $revision);
    $quote = $quote->fresh();

    $workflow = app(QuoteApprovalWorkflowService::class);

    $evaluation = $workflow->evaluate($quote, $revision, actor: $ctx['user']);
    expect($evaluation->requiresApproval)->toBeTrue()
        ->and($evaluation->reasons)->toBe(['over_threshold'])
        ->and($evaluation->thresholdBasisCents)->toBe(150_001)
        ->and(AuditEvent::query()->where('action', 'crm.quote.approval_evaluated')->count())->toBe(1);

    $submitted = $workflow->submitForApproval(
        quote: $quote,
        revision: $revision,
        expectedQuoteLockVersion: $quote->lock_version,
        expectedRevisionLockVersion: $revision->lock_version,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    $request = QuoteApprovalRequest::query()->sole();

    expect($submitted->status)->toBe(QuoteRevisionStatus::PendingApproval)
        ->and($submitted->approval_required)->toBeTrue()
        ->and($submitted->current_approval_request_id)->toBe($request->id)
        ->and($request->status)->toBe(QuoteApprovalRequestStatus::Pending)
        ->and($request->request_version)->toBe(1)
        ->and($request->rule_snapshot_json['reasons'])->toBe(['over_threshold'])
        ->and($request->requested_by_membership_id)->toBe($ctx['membership']->id)
        ->and(QuoteApprovalDecision::query()->count())->toBe(0);

    $quote = $quote->fresh();

    $workflow->approve(
        request: $request,
        expectedQuoteLockVersion: $quote->lock_version,
        expectedRevisionLockVersion: $submitted->lock_version,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    $decision = QuoteApprovalDecision::query()->sole();

    expect($request->fresh()->status)->toBe(QuoteApprovalRequestStatus::Approved)
        ->and($request->fresh()->resolved_at)->not->toBeNull()
        ->and($submitted->fresh()->status)->toBe(QuoteRevisionStatus::Approved)
        ->and($decision->decision)->toBe(QuoteApprovalDecisionType::Approved)
        ->and($decision->approver_membership_id)->toBe($ctx['membership']->id)
        // The submitter approved their own quote; the trail says so rather than
        // implying a second pair of eyes.
        ->and($decision->metadata_json['self_approval'])->toBeTrue();

    $granted = AuditEvent::query()->where('action', 'crm.quote.approval_granted')->sole();
    expect($granted->after_json['self_approval'])->toBeTrue();

    // Repeating the approval is the same outcome, not a second decision.
    $workflow->approve(
        request: $request->fresh(),
        expectedQuoteLockVersion: $quote->fresh()->lock_version,
        expectedRevisionLockVersion: $submitted->fresh()->lock_version,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    expect(QuoteApprovalDecision::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'crm.quote.approval_granted')->count())->toBe(1);
});

test('a quote with nothing to flag is approved by the system without a decision', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision, 'organizationCompany' => $company] =
        Phase2C2Fixture::draftQuote(lineUnitPriceCents: 100_000);

    Phase2C2Fixture::makeCustomerEstablished($company);
    $revision = phase2c2ResolveTax($ctx, $quote, $revision);
    $quote = $quote->fresh();

    $submitted = app(QuoteApprovalWorkflowService::class)->submitForApproval(
        quote: $quote,
        revision: $revision,
        expectedQuoteLockVersion: $quote->lock_version,
        expectedRevisionLockVersion: $revision->lock_version,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    expect($submitted->status)->toBe(QuoteRevisionStatus::Approved)
        ->and($submitted->approval_required)->toBeFalse()
        ->and($submitted->current_approval_request_id)->toBeNull()
        ->and(QuoteApprovalRequest::query()->count())->toBe(0)
        ->and(QuoteApprovalDecision::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'crm.quote.approval_auto_completed')->count())->toBe(1);

    $event = QuoteStatusEvent::query()
        ->where('quote_revision_id', $submitted->id)
        ->where('to_status', QuoteRevisionStatus::Approved->value)
        ->sole();

    expect($event->transition_source)->toBe(QuoteStatusTransitionSource::System);
});

test('a rejection needs a reason, returns the quote to draft, and keeps the tax figure', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] =
        Phase2C2Fixture::draftQuote(lineUnitPriceCents: 200_000);

    $revision = phase2c2ResolveTax($ctx, $quote, $revision);
    $quote = $quote->fresh();
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

    expect(fn () => $workflow->reject(
        request: $request,
        expectedQuoteLockVersion: $quote->lock_version,
        expectedRevisionLockVersion: $submitted->lock_version,
        reason: '  ',
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    ))->toThrow(InvalidQuoteApprovalException::class);

    $workflow->reject(
        request: $request,
        expectedQuoteLockVersion: $quote->lock_version,
        expectedRevisionLockVersion: $submitted->lock_version,
        reason: 'Margin is too thin for this customer',
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    $revision = $submitted->fresh();
    $decision = QuoteApprovalDecision::query()->sole();

    expect($request->fresh()->status)->toBe(QuoteApprovalRequestStatus::Rejected)
        ->and($revision->status)->toBe(QuoteRevisionStatus::Draft)
        ->and($revision->current_approval_request_id)->toBeNull()
        ->and($revision->tax_calculation_status)->toBe(QuoteTaxCalculationStatus::Calculated)
        ->and($revision->tax_cents)->toBe(16_000)
        ->and($decision->decision)->toBe(QuoteApprovalDecisionType::Rejected)
        ->and($decision->reason)->toBe('Margin is too thin for this customer');
});

test('a submitter can withdraw a pending quote and the open request is cancelled', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] =
        Phase2C2Fixture::draftQuote(lineUnitPriceCents: 200_000);

    $revision = phase2c2ResolveTax($ctx, $quote, $revision);
    $quote = $quote->fresh();
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

    $withdrawn = $workflow->withdrawToDraft(
        quote: $quote,
        revision: $submitted,
        expectedQuoteLockVersion: $quote->lock_version,
        expectedRevisionLockVersion: $submitted->lock_version,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    expect($withdrawn->status)->toBe(QuoteRevisionStatus::Draft)
        ->and($withdrawn->current_approval_request_id)->toBeNull()
        ->and($request->fresh()->status)->toBe(QuoteApprovalRequestStatus::Cancelled)
        ->and(QuoteApprovalDecision::query()->count())->toBe(0)
        ->and($withdrawn->tax_calculation_status)->toBe(QuoteTaxCalculationStatus::Calculated);

    // Resubmitting opens a new request rather than reusing the cancelled one.
    $resubmitted = $workflow->submitForApproval(
        quote: $quote->fresh(),
        revision: $withdrawn,
        expectedQuoteLockVersion: $quote->fresh()->lock_version,
        expectedRevisionLockVersion: $withdrawn->lock_version,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    expect(QuoteApprovalRequest::query()->count())->toBe(2)
        ->and($resubmitted->current_approval_request_id)->not->toBe($request->id)
        ->and(QuoteApprovalRequest::query()->find($resubmitted->current_approval_request_id)->request_version)
        ->toBe(2);
});

test('reopening an approved quote invalidates the approval but not the tax', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] =
        Phase2C2Fixture::draftQuote(lineUnitPriceCents: 200_000);

    $revision = phase2c2ResolveTax($ctx, $quote, $revision);
    $quote = $quote->fresh();
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

    $workflow->approve(
        request: $request,
        expectedQuoteLockVersion: $quote->lock_version,
        expectedRevisionLockVersion: $submitted->lock_version,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    $approved = $submitted->fresh();
    $quote = $quote->fresh();

    $reopened = $workflow->returnApprovedToDraft(
        quote: $quote,
        revision: $approved,
        expectedQuoteLockVersion: $quote->lock_version,
        expectedRevisionLockVersion: $approved->lock_version,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    expect($reopened->status)->toBe(QuoteRevisionStatus::Draft)
        ->and($reopened->current_approval_request_id)->toBeNull()
        ->and($reopened->approval_required)->toBeTrue()
        ->and($reopened->tax_calculation_status)->toBe(QuoteTaxCalculationStatus::Calculated)
        ->and($reopened->tax_cents)->toBe(16_000)
        ->and(AuditEvent::query()->where('action', 'crm.quote.approval_invalidated')->count())->toBe(1);
});

test('approval decisions refuse stale lock versions and out-of-state revisions', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] =
        Phase2C2Fixture::draftQuote(lineUnitPriceCents: 200_000);

    $revision = phase2c2ResolveTax($ctx, $quote, $revision);
    $quote = $quote->fresh();
    $workflow = app(QuoteApprovalWorkflowService::class);

    expect(fn () => $workflow->submitForApproval(
        quote: $quote,
        revision: $revision,
        expectedQuoteLockVersion: $quote->lock_version,
        expectedRevisionLockVersion: $revision->lock_version - 1,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    ))->toThrow(StaleQuoteStateException::class);

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

    expect(fn () => $workflow->approve(
        request: $request,
        expectedQuoteLockVersion: $quote->lock_version,
        expectedRevisionLockVersion: $submitted->lock_version - 1,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    ))->toThrow(StaleQuoteStateException::class);

    // An approval decision must name the membership that made it.
    expect(fn () => $workflow->approve(
        request: $request,
        expectedQuoteLockVersion: $quote->lock_version,
        expectedRevisionLockVersion: $submitted->lock_version,
        actor: $ctx['user'],
    ))->toThrow(InvalidQuoteApprovalException::class);

    $workflow->approve(
        request: $request,
        expectedQuoteLockVersion: $quote->lock_version,
        expectedRevisionLockVersion: $submitted->lock_version,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    // The quote is approved now, so withdrawing is no longer the right move.
    expect(fn () => $workflow->withdrawToDraft(
        quote: $quote->fresh(),
        revision: $submitted->fresh(),
        expectedQuoteLockVersion: $quote->fresh()->lock_version,
        expectedRevisionLockVersion: $submitted->fresh()->lock_version,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    ))->toThrow(InvalidQuoteApprovalException::class);
});
