<?php

namespace App\Support\Quotes\Approval;

use App\Enums\DealStage;
use App\Enums\QuoteAdjustmentType;
use App\Enums\QuoteApprovalDecisionType;
use App\Enums\QuoteApprovalRequestStatus;
use App\Enums\QuoteLineDiscountMethod;
use App\Enums\QuoteLineType;
use App\Enums\QuoteRevisionStatus;
use App\Enums\QuoteStatusTransitionSource;
use App\Models\Deal;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationCompany;
use App\Models\ParentAccount;
use App\Models\Quote;
use App\Models\QuoteApprovalDecision;
use App\Models\QuoteApprovalRequest;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionAdjustment;
use App\Models\QuoteRevisionLineItem;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Quotes\QuoteApprovalInvalidationContract;
use App\Support\Quotes\QuoteRevisionTransitionService;
use App\Support\Quotes\StaleQuoteStateException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Runs the approval lifecycle of a quote revision.
 *
 * Two rules shape everything here:
 *
 * 1. The reasons are always recomputed on the server from the rows as they stand. A
 *    client may ask for a manual escalation, but it can never tell us that a quote does
 *    not need approval.
 * 2. Tax must be resolved before approval is even possible. Nobody can approve a total
 *    that is not final, so a pending or review-required tax position blocks submission.
 *
 * When nothing triggers approval the revision goes straight to approved with a system
 * transition and no decision row: inventing a human decision nobody made would corrupt
 * the approval trail. When something does trigger it, exactly one pending request exists
 * at a time, decisions are append-only, and a rejection sends the revision back to draft
 * while keeping the tax figure it already had.
 *
 * Self-approval is allowed — a small shop may have one person who both quotes and
 * approves — but it is recorded as such, so the audit trail never implies a second pair
 * of eyes that was not there.
 *
 * Nothing here sends a quote. Permission checks belong to the caller:
 * `crm.quote.update` to submit or withdraw, `crm.quote.approve` to decide.
 */
final class QuoteApprovalWorkflowService
{
    public function __construct(
        private QuoteApprovalEvaluator $evaluator,
        private QuoteApprovalInvalidationContract $contract,
        private QuoteRevisionTransitionService $transitions,
        private Auditor $auditor,
    ) {}

    /**
     * Recompute why this revision would need approval, and record that we looked.
     */
    public function evaluate(
        Quote $quote,
        QuoteRevision $revision,
        bool $manualEscalation = false,
        ?User $actor = null,
    ): QuoteApprovalEvaluationResult {
        $evaluation = $this->evaluateFacts($quote, $revision, $manualEscalation);

        $this->audit(
            $quote,
            $revision,
            'crm.quote.approval_evaluated',
            null,
            $this->evaluationPayload($evaluation),
            $actor,
            (string) Str::uuid(),
        );

        return $evaluation;
    }

    /**
     * Move a draft out of editing: either into pending approval with a request that
     * names its reasons, or straight to approved when nothing triggers.
     */
    public function submitForApproval(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedQuoteLockVersion,
        int $expectedRevisionLockVersion,
        ?User $actor = null,
        ?Membership $actorMembership = null,
        bool $manualEscalation = false,
    ): QuoteRevision {
        return DB::transaction(function () use (
            $quote,
            $revision,
            $expectedQuoteLockVersion,
            $expectedRevisionLockVersion,
            $actor,
            $actorMembership,
            $manualEscalation,
        ): QuoteRevision {
            ['quote' => $lockedQuote, 'revision' => $lockedRevision] = $this->lockPair(
                $quote->id,
                $revision->id,
                $expectedQuoteLockVersion,
                $expectedRevisionLockVersion,
            );

            $this->assertStatus($lockedRevision, QuoteRevisionStatus::Draft, 'submitted for approval');

            if ($this->evaluator->taxBlocksApproval($lockedRevision->tax_calculation_status)) {
                throw new InvalidQuoteApprovalException(sprintf(
                    'Quote revision [%d] has an unresolved tax position (%s) and cannot be submitted for approval.',
                    $lockedRevision->id,
                    $lockedRevision->tax_calculation_status->value,
                ));
            }

            $evaluation = $this->evaluateFacts($lockedQuote, $lockedRevision, $manualEscalation);
            $correlationId = (string) Str::uuid();

            $lockedRevision->forceFill([
                'approval_required' => $evaluation->requiresApproval,
                'approval_reason_snapshot' => $this->evaluationPayload($evaluation),
            ])->save();

            if (! $evaluation->requiresApproval) {
                $this->transitions->transition(
                    quote: $lockedQuote,
                    revision: $lockedRevision,
                    to: QuoteRevisionStatus::Approved,
                    source: QuoteStatusTransitionSource::System,
                    expectedQuoteLockVersion: $expectedQuoteLockVersion,
                    expectedRevisionLockVersion: $expectedRevisionLockVersion,
                    actor: $actor,
                    actorMembership: $actorMembership,
                    metadata: ['auto_approved' => true, 'reasons' => []],
                );

                $this->audit(
                    $lockedQuote,
                    $lockedRevision,
                    'crm.quote.approval_auto_completed',
                    ['status' => QuoteRevisionStatus::Draft->value],
                    [
                        'status' => QuoteRevisionStatus::Approved->value,
                        'approval_required' => false,
                        'decision_recorded' => false,
                    ],
                    $actor,
                    $correlationId,
                );

                return $this->fresh($lockedRevision);
            }

            if ($actorMembership === null) {
                throw new InvalidQuoteApprovalException(
                    'An approval request must record the membership that asked for it.'
                );
            }

            $request = QuoteApprovalRequest::query()->create([
                'parent_account_id' => $lockedRevision->parent_account_id,
                'organization_id' => $lockedRevision->organization_id,
                'quote_id' => $lockedQuote->id,
                'quote_revision_id' => $lockedRevision->id,
                'request_version' => $this->nextRequestVersion($lockedRevision),
                'status' => QuoteApprovalRequestStatus::Pending,
                'rule_snapshot_json' => $this->evaluationPayload($evaluation),
                'requested_by_membership_id' => $actorMembership->id,
                'requested_by_user_id' => $actorMembership->user_id,
                'requested_at' => now(),
                'correlation_id' => $correlationId,
            ]);

            $lockedRevision->forceFill(['current_approval_request_id' => $request->id])->save();

            $this->transitions->transition(
                quote: $lockedQuote,
                revision: $lockedRevision,
                to: QuoteRevisionStatus::PendingApproval,
                source: QuoteStatusTransitionSource::User,
                expectedQuoteLockVersion: $expectedQuoteLockVersion,
                expectedRevisionLockVersion: $expectedRevisionLockVersion,
                actor: $actor,
                actorMembership: $actorMembership,
                metadata: [
                    'approval_request_id' => $request->id,
                    'reasons' => $evaluation->reasons,
                ],
            );

            $this->audit(
                $lockedQuote,
                $lockedRevision,
                'crm.quote.approval_requested',
                null,
                [
                    'approval_request_id' => $request->id,
                    'request_version' => $request->request_version,
                    ...$this->evaluationPayload($evaluation),
                ],
                $actor,
                $correlationId,
            );

            return $this->fresh($lockedRevision);
        });
    }

    /**
     * Grant a pending request. Repeating an approval that already went through is a
     * no-op rather than a second decision, so a retried request cannot double-write.
     */
    public function approve(
        QuoteApprovalRequest $request,
        int $expectedQuoteLockVersion,
        int $expectedRevisionLockVersion,
        ?User $actor = null,
        ?Membership $actorMembership = null,
    ): void {
        DB::transaction(function () use (
            $request,
            $expectedQuoteLockVersion,
            $expectedRevisionLockVersion,
            $actor,
            $actorMembership,
        ): void {
            ['quote' => $lockedQuote, 'revision' => $lockedRevision] = $this->lockPair(
                $request->quote_id,
                $request->quote_revision_id,
                $expectedQuoteLockVersion,
                $expectedRevisionLockVersion,
                verifyVersions: false,
            );

            $lockedRequest = $this->lockRequest($request);

            // Retry safety comes before the version gate: a caller repeating an approval
            // it already made would otherwise be told its state is stale, and the honest
            // answer is that the work is already done.
            if ($lockedRequest->status === QuoteApprovalRequestStatus::Approved
                && $lockedRevision->status === QuoteRevisionStatus::Approved) {
                return;
            }

            $this->assertVersions(
                $lockedQuote,
                $lockedRevision,
                $expectedQuoteLockVersion,
                $expectedRevisionLockVersion,
            );
            $this->assertPending($lockedRequest);
            $this->assertStatus($lockedRevision, QuoteRevisionStatus::PendingApproval, 'approved');

            $approver = $this->requireApprover($actorMembership);
            $selfApproval = $this->isSelfApproval($lockedRequest, $actor, $approver);
            $correlationId = (string) Str::uuid();

            $this->recordDecision(
                $lockedRequest,
                QuoteApprovalDecisionType::Approved,
                $approver,
                null,
                ['self_approval' => $selfApproval, 'reasons' => $this->requestReasons($lockedRequest)],
            );

            $lockedRequest->update([
                'status' => QuoteApprovalRequestStatus::Approved,
                'resolved_at' => now(),
            ]);

            $this->transitions->transition(
                quote: $lockedQuote,
                revision: $lockedRevision,
                to: QuoteRevisionStatus::Approved,
                source: QuoteStatusTransitionSource::Approval,
                expectedQuoteLockVersion: $expectedQuoteLockVersion,
                expectedRevisionLockVersion: $expectedRevisionLockVersion,
                actor: $actor,
                actorMembership: $approver,
                metadata: [
                    'approval_request_id' => $lockedRequest->id,
                    'self_approval' => $selfApproval,
                ],
            );

            $this->audit(
                $lockedQuote,
                $lockedRevision,
                'crm.quote.approval_granted',
                ['status' => QuoteRevisionStatus::PendingApproval->value],
                [
                    'status' => QuoteRevisionStatus::Approved->value,
                    'approval_request_id' => $lockedRequest->id,
                    'self_approval' => $selfApproval,
                ],
                $actor,
                $correlationId,
            );
        });
    }

    /**
     * Refuse a pending request and hand the revision back for editing. The tax figure
     * stays: the numbers did not change, the answer to them did.
     */
    public function reject(
        QuoteApprovalRequest $request,
        int $expectedQuoteLockVersion,
        int $expectedRevisionLockVersion,
        string $reason,
        ?User $actor = null,
        ?Membership $actorMembership = null,
    ): void {
        if (trim($reason) === '') {
            throw new InvalidQuoteApprovalException('Rejecting an approval request requires a reason.');
        }

        DB::transaction(function () use (
            $request,
            $expectedQuoteLockVersion,
            $expectedRevisionLockVersion,
            $reason,
            $actor,
            $actorMembership,
        ): void {
            ['quote' => $lockedQuote, 'revision' => $lockedRevision] = $this->lockPair(
                $request->quote_id,
                $request->quote_revision_id,
                $expectedQuoteLockVersion,
                $expectedRevisionLockVersion,
            );

            $lockedRequest = $this->lockRequest($request);

            $this->assertPending($lockedRequest);
            $this->assertStatus($lockedRevision, QuoteRevisionStatus::PendingApproval, 'rejected');

            $approver = $this->requireApprover($actorMembership);
            $correlationId = (string) Str::uuid();

            $this->recordDecision(
                $lockedRequest,
                QuoteApprovalDecisionType::Rejected,
                $approver,
                trim($reason),
                ['reasons' => $this->requestReasons($lockedRequest)],
            );

            $lockedRequest->update([
                'status' => QuoteApprovalRequestStatus::Rejected,
                'resolved_at' => now(),
            ]);

            $lockedRevision->forceFill(['current_approval_request_id' => null])->save();

            $this->transitions->transition(
                quote: $lockedQuote,
                revision: $lockedRevision,
                to: QuoteRevisionStatus::Draft,
                source: QuoteStatusTransitionSource::Approval,
                expectedQuoteLockVersion: $expectedQuoteLockVersion,
                expectedRevisionLockVersion: $expectedRevisionLockVersion,
                actor: $actor,
                actorMembership: $approver,
                metadata: ['approval_request_id' => $lockedRequest->id, 'rejected' => true],
            );

            $this->audit(
                $lockedQuote,
                $lockedRevision,
                'crm.quote.approval_rejected',
                ['status' => QuoteRevisionStatus::PendingApproval->value],
                [
                    'status' => QuoteRevisionStatus::Draft->value,
                    'approval_request_id' => $lockedRequest->id,
                    'tax_calculation_status' => $lockedRevision->tax_calculation_status->value,
                ],
                $actor,
                $correlationId,
            );
        });
    }

    /**
     * The submitter takes the quote back before anyone decided. The open request is
     * cancelled rather than left dangling, and no decision is written.
     */
    public function withdrawToDraft(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedQuoteLockVersion,
        int $expectedRevisionLockVersion,
        ?User $actor = null,
        ?Membership $actorMembership = null,
    ): QuoteRevision {
        return DB::transaction(function () use (
            $quote,
            $revision,
            $expectedQuoteLockVersion,
            $expectedRevisionLockVersion,
            $actor,
            $actorMembership,
        ): QuoteRevision {
            ['quote' => $lockedQuote, 'revision' => $lockedRevision] = $this->lockPair(
                $quote->id,
                $revision->id,
                $expectedQuoteLockVersion,
                $expectedRevisionLockVersion,
            );

            $this->assertStatus($lockedRevision, QuoteRevisionStatus::PendingApproval, 'withdrawn to draft');

            $cancelled = $this->resolveOpenRequests(
                $lockedRevision,
                QuoteApprovalRequestStatus::Cancelled,
            );

            $lockedRevision->forceFill(['current_approval_request_id' => null])->save();

            $correlationId = (string) Str::uuid();

            $this->transitions->transition(
                quote: $lockedQuote,
                revision: $lockedRevision,
                to: QuoteRevisionStatus::Draft,
                source: QuoteStatusTransitionSource::User,
                expectedQuoteLockVersion: $expectedQuoteLockVersion,
                expectedRevisionLockVersion: $expectedRevisionLockVersion,
                actor: $actor,
                actorMembership: $actorMembership,
                metadata: ['withdrawn' => true, 'cancelled_request_ids' => $cancelled],
            );

            $this->audit(
                $lockedQuote,
                $lockedRevision,
                'crm.quote.approval_withdrawn',
                ['status' => QuoteRevisionStatus::PendingApproval->value],
                [
                    'status' => QuoteRevisionStatus::Draft->value,
                    'cancelled_request_ids' => $cancelled,
                ],
                $actor,
                $correlationId,
            );

            return $this->fresh($lockedRevision);
        });
    }

    /**
     * Reopen an approved but unsent revision for editing.
     *
     * The approval described numbers that are about to change, so it is invalidated and
     * the revision must be approved again. The tax figure survives: reopening for editing
     * is not itself an edit, and the draft mutators invalidate tax the moment money moves.
     */
    public function returnApprovedToDraft(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedQuoteLockVersion,
        int $expectedRevisionLockVersion,
        ?User $actor = null,
        ?Membership $actorMembership = null,
    ): QuoteRevision {
        return DB::transaction(function () use (
            $quote,
            $revision,
            $expectedQuoteLockVersion,
            $expectedRevisionLockVersion,
            $actor,
            $actorMembership,
        ): QuoteRevision {
            ['quote' => $lockedQuote, 'revision' => $lockedRevision] = $this->lockPair(
                $quote->id,
                $revision->id,
                $expectedQuoteLockVersion,
                $expectedRevisionLockVersion,
            );

            $this->assertStatus($lockedRevision, QuoteRevisionStatus::Approved, 'returned to draft');

            $superseded = $this->contract->markPendingRequestsSuperseded($lockedRevision);
            $invalidatedRequestId = $lockedRevision->current_approval_request_id;

            // `approval_required` and the reason snapshot still describe the lines, which
            // have not changed, so they stay; only the grant is invalidated.
            $lockedRevision->forceFill(['current_approval_request_id' => null])->save();

            $correlationId = (string) Str::uuid();

            $this->transitions->transition(
                quote: $lockedQuote,
                revision: $lockedRevision,
                to: QuoteRevisionStatus::Draft,
                source: QuoteStatusTransitionSource::User,
                expectedQuoteLockVersion: $expectedQuoteLockVersion,
                expectedRevisionLockVersion: $expectedRevisionLockVersion,
                actor: $actor,
                actorMembership: $actorMembership,
                metadata: [
                    'approval_invalidated' => true,
                    'invalidated_request_id' => $invalidatedRequestId,
                ],
            );

            $this->audit(
                $lockedQuote,
                $lockedRevision,
                'crm.quote.approval_invalidated',
                [
                    'status' => QuoteRevisionStatus::Approved->value,
                    'current_approval_request_id' => $invalidatedRequestId,
                ],
                [
                    'status' => QuoteRevisionStatus::Draft->value,
                    'current_approval_request_id' => null,
                    'superseded_request_count' => $superseded,
                    'tax_calculation_status' => $lockedRevision->tax_calculation_status->value,
                ],
                $actor,
                $correlationId,
            );

            return $this->fresh($lockedRevision);
        });
    }

    /**
     * Server-side recomputation. Client input is limited to asking for an escalation.
     */
    private function evaluateFacts(
        Quote $quote,
        QuoteRevision $revision,
        bool $manualEscalation,
    ): QuoteApprovalEvaluationResult {
        $company = OrganizationCompany::query()->find($quote->organization_company_id);

        /** @var Collection<int, QuoteRevisionLineItem> $lines */
        $lines = QuoteRevisionLineItem::query()
            ->where('quote_revision_id', $revision->id)
            ->get();

        /** @var Collection<int, QuoteRevisionAdjustment> $adjustments */
        $adjustments = QuoteRevisionAdjustment::query()
            ->where('quote_revision_id', $revision->id)
            ->get();

        return $this->evaluator->evaluate(new QuoteApprovalEvaluationInput(
            // The stored grand total already carries resolved tax; approval is judged on
            // the pretax amount, so the tax is taken back out here.
            finalPretaxAmountCents: $revision->grand_total_cents - $revision->tax_cents,
            organizationCompanyIsNew: $company === null || $company->relationship_status === 'new',
            hasPreviouslyWonDeal: $company !== null && $this->hasPreviouslyWonDeal($company),
            organizationCompanyIsFlagged: $company !== null && $company->is_flagged,
            hasCustomLine: $lines->contains(
                fn (QuoteRevisionLineItem $line): bool => $line->line_type === QuoteLineType::Custom
            ),
            hasPriceOverride: $lines->contains(
                fn (QuoteRevisionLineItem $line): bool => $line->price_override
            ),
            hasBelowMinimumLine: $lines->contains(
                fn (QuoteRevisionLineItem $line): bool => $line->below_minimum
            ),
            hasMarginOverride: $lines->contains(
                fn (QuoteRevisionLineItem $line): bool => $this->erodesMargin($line)
            ),
            hasQuoteDiscount: $adjustments->contains(
                fn (QuoteRevisionAdjustment $adjustment): bool => $adjustment->adjustment_type === QuoteAdjustmentType::QuoteDiscount
            ),
            hasLineDiscount: $lines->contains(
                fn (QuoteRevisionLineItem $line): bool => $line->line_discount_method !== QuoteLineDiscountMethod::None
                    && $line->line_discount_value > 0
            ),
            manualEscalationRequested: $manualEscalation,
        ));
    }

    private function hasPreviouslyWonDeal(OrganizationCompany $company): bool
    {
        return Deal::query()
            ->where('organization_company_id', $company->id)
            ->where('stage', DealStage::QuoteWon)
            ->exists();
    }

    private function erodesMargin(QuoteRevisionLineItem $line): bool
    {
        return $line->price_override
            && $line->calculated_unit_price_cents !== null
            && $line->final_unit_price_cents !== null
            && $line->final_unit_price_cents < $line->calculated_unit_price_cents;
    }

    /**
     * @return array{quote: Quote, revision: QuoteRevision}
     */
    private function lockPair(
        int $quoteId,
        int $revisionId,
        int $expectedQuoteLockVersion,
        int $expectedRevisionLockVersion,
        bool $verifyVersions = true,
    ): array {
        /** @var Quote $lockedQuote */
        $lockedQuote = Quote::query()
            ->whereKey($quoteId)
            ->lockForUpdate()
            ->firstOrFail();

        /** @var QuoteRevision $lockedRevision */
        $lockedRevision = QuoteRevision::query()
            ->whereKey($revisionId)
            ->where('quote_id', $lockedQuote->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($verifyVersions) {
            $this->assertVersions(
                $lockedQuote,
                $lockedRevision,
                $expectedQuoteLockVersion,
                $expectedRevisionLockVersion,
            );
        }

        return ['quote' => $lockedQuote, 'revision' => $lockedRevision];
    }

    private function assertVersions(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedQuoteLockVersion,
        int $expectedRevisionLockVersion,
    ): void {
        if ($quote->lock_version !== $expectedQuoteLockVersion
            || $revision->lock_version !== $expectedRevisionLockVersion) {
            throw new StaleQuoteStateException;
        }
    }

    private function lockRequest(QuoteApprovalRequest $request): QuoteApprovalRequest
    {
        /** @var QuoteApprovalRequest $locked */
        $locked = QuoteApprovalRequest::query()
            ->whereKey($request->id)
            ->lockForUpdate()
            ->firstOrFail();

        return $locked;
    }

    private function assertPending(QuoteApprovalRequest $request): void
    {
        if ($request->status === QuoteApprovalRequestStatus::Pending) {
            return;
        }

        throw new InvalidQuoteApprovalException(sprintf(
            'Approval request [%d] is already %s.',
            $request->id,
            $request->status->value,
        ));
    }

    private function assertStatus(QuoteRevision $revision, QuoteRevisionStatus $expected, string $action): void
    {
        if ($revision->status === $expected) {
            return;
        }

        throw new InvalidQuoteApprovalException(sprintf(
            'Quote revision [%d] is %s and cannot be %s.',
            $revision->id,
            $revision->status->value,
            $action,
        ));
    }

    private function requireApprover(?Membership $actorMembership): Membership
    {
        if ($actorMembership === null) {
            throw new InvalidQuoteApprovalException(
                'An approval decision must record the membership that made it.'
            );
        }

        return $actorMembership;
    }

    private function isSelfApproval(
        QuoteApprovalRequest $request,
        ?User $actor,
        Membership $approver,
    ): bool {
        if ($request->requested_by_membership_id === $approver->id) {
            return true;
        }

        return $actor !== null && $request->requested_by_user_id === $actor->id;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordDecision(
        QuoteApprovalRequest $request,
        QuoteApprovalDecisionType $decision,
        Membership $approver,
        ?string $reason,
        array $metadata,
    ): QuoteApprovalDecision {
        return QuoteApprovalDecision::query()->create([
            'parent_account_id' => $request->parent_account_id,
            'organization_id' => $request->organization_id,
            'quote_approval_request_id' => $request->id,
            'quote_id' => $request->quote_id,
            'quote_revision_id' => $request->quote_revision_id,
            'decision' => $decision,
            'approver_membership_id' => $approver->id,
            'approver_user_id' => $approver->user_id,
            'reason' => $reason,
            'metadata_json' => $metadata,
            'decided_at' => now(),
        ]);
    }

    /**
     * Close every still-open request on the revision without writing a decision.
     *
     * @return list<int>
     */
    private function resolveOpenRequests(
        QuoteRevision $revision,
        QuoteApprovalRequestStatus $status,
    ): array {
        $open = QuoteApprovalRequest::query()
            ->where('quote_revision_id', $revision->id)
            ->where('status', QuoteApprovalRequestStatus::Pending)
            ->lockForUpdate()
            ->get();

        $resolved = [];

        foreach ($open as $request) {
            $request->update(['status' => $status, 'resolved_at' => now()]);
            $resolved[] = $request->id;
        }

        return $resolved;
    }

    private function nextRequestVersion(QuoteRevision $revision): int
    {
        return (int) QuoteApprovalRequest::query()
            ->where('quote_revision_id', $revision->id)
            ->max('request_version') + 1;
    }

    /**
     * @return list<string>
     */
    private function requestReasons(QuoteApprovalRequest $request): array
    {
        $reasons = $request->rule_snapshot_json['reasons'] ?? [];

        if (! is_array($reasons)) {
            return [];
        }

        return array_values(array_filter($reasons, static fn (mixed $reason): bool => is_string($reason)));
    }

    /**
     * @return array{
     *     reasons: list<string>,
     *     explanations: array<string, string>,
     *     requires_approval: bool,
     *     threshold_basis_cents: int,
     *     meets_monetary_threshold: bool
     * }
     */
    private function evaluationPayload(QuoteApprovalEvaluationResult $evaluation): array
    {
        return [
            'reasons' => $evaluation->reasons,
            'explanations' => $evaluation->explanations,
            'requires_approval' => $evaluation->requiresApproval,
            'threshold_basis_cents' => $evaluation->thresholdBasisCents,
            'meets_monetary_threshold' => $evaluation->meetsMonetaryThreshold,
        ];
    }

    private function fresh(QuoteRevision $revision): QuoteRevision
    {
        return $revision->fresh() ?? $revision;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(
        Quote $quote,
        QuoteRevision $revision,
        string $action,
        ?array $before,
        array $after,
        ?User $actor,
        string $correlationId,
    ): void {
        $this->auditor->append(
            parentAccount: ParentAccount::query()->findOrFail($quote->parent_account_id),
            action: $action,
            subjectType: QuoteRevision::class,
            subjectId: $revision->id,
            organization: Organization::query()->findOrFail($quote->organization_id),
            actor: $actor,
            before: $before,
            after: $after,
            correlationId: $correlationId,
        );
    }
}
