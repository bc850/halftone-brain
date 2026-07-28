<?php

namespace App\Support\Quotes;

use App\Enums\QuoteTaxCalculationStatus;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\User;
use App\Support\Audit\Auditor;

/**
 * Applies {@see QuoteApprovalInvalidationContract} to a persisted revision whose
 * financial content just changed.
 *
 * A tax figure and an approval both describe specific numbers. Once those numbers
 * move, the tax result no longer describes the quote and the approval no longer
 * describes what was approved, so both are dropped:
 *
 * - Tax calculation history is kept — it is append-only and explains what was relied
 *   on at the time — but the revision stops pointing at it, returns to `pending`, and
 *   gives back the tax it had added to the grand total.
 * - Any pending approval request is superseded rather than left open, which also frees
 *   the one-pending-per-revision slot, and the revision stops pointing at it.
 * - Nothing here writes an approval decision. Decisions are append-only; only request
 *   status moves.
 *
 * `approval_required` and `approval_reason_snapshot` are deliberately left alone: the
 * draft mutators resynchronize totals right after this runs and rebuild both from the
 * lines and adjustments as they now stand.
 *
 * The caller already holds the row locks and owns the single `lock_version` bump for
 * the user action, so `$bumpLock` stays false on every draft-mutator path. Callers that
 * have no other write of their own — a party snapshot edit, say — pass their own
 * correlation id so the invalidation audit ties back to the action that caused it.
 */
final class QuoteApprovalInvalidationService
{
    public function __construct(
        private QuoteApprovalInvalidationContract $contract,
        private QuoteDraftLock $lock,
        private Auditor $auditor,
    ) {}

    /**
     * @param  bool  $bumpLock  true only when the caller has no bump of its own
     *
     * @throws ImmutableQuoteRevisionException when the revision is customer-visible
     */
    public function invalidateForFinancialMutation(
        Quote $quote,
        QuoteRevision $revision,
        ?User $actor,
        string $correlationId,
        bool $bumpLock = false,
    ): void {
        $this->contract->assertMayMutateFinancialContent($revision);

        $taxBefore = $this->taxPayload($revision);
        $taxCleared = $this->clearTax($revision);

        $approvalBefore = ['current_approval_request_id' => $revision->current_approval_request_id];
        $superseded = $this->contract->markPendingRequestsSuperseded($revision);
        $pointerCleared = $revision->current_approval_request_id !== null;

        if ($pointerCleared) {
            $revision->forceFill(['current_approval_request_id' => null])->save();
        }

        if ($bumpLock && ($taxCleared || $superseded > 0 || $pointerCleared)) {
            $this->lock->bumpRevisionLock($revision);
        }

        if ($taxCleared) {
            $this->audit(
                $quote,
                $revision,
                'crm.quote.tax_invalidated',
                $taxBefore,
                $this->taxPayload($revision),
                $actor,
                $correlationId,
            );
        }

        if ($superseded > 0 || $pointerCleared) {
            $this->audit(
                $quote,
                $revision,
                'crm.quote.approval_invalidated',
                $approvalBefore,
                [
                    'current_approval_request_id' => null,
                    'superseded_request_count' => $superseded,
                ],
                $actor,
                $correlationId,
            );
        }
    }

    /**
     * Give back the tax the grand total was carrying and return the revision to an
     * unresolved tax position. Returns whether anything actually changed.
     */
    private function clearTax(QuoteRevision $revision): bool
    {
        $hasResolvedTax = $revision->current_tax_calculation_id !== null
            || $revision->tax_calculation_status !== QuoteTaxCalculationStatus::Pending
            || $revision->tax_cents !== 0
            || $revision->tax_snapshot_json !== null
            || $revision->tax_calculated_at !== null;

        if (! $hasResolvedTax) {
            return false;
        }

        $revision->forceFill([
            'current_tax_calculation_id' => null,
            'tax_calculation_status' => QuoteTaxCalculationStatus::Pending,
            'tax_snapshot_json' => null,
            'tax_calculated_at' => null,
            'tax_cents' => 0,
            'grand_total_cents' => $revision->grand_total_cents - $revision->tax_cents,
        ])->save();

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function taxPayload(QuoteRevision $revision): array
    {
        return [
            'current_tax_calculation_id' => $revision->current_tax_calculation_id,
            'tax_calculation_status' => $revision->tax_calculation_status->value,
            'tax_cents' => $revision->tax_cents,
            'grand_total_cents' => $revision->grand_total_cents,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(
        Quote $quote,
        QuoteRevision $revision,
        string $action,
        array $before,
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
