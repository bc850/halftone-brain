<?php

namespace App\Support\Quotes;

use App\Enums\QuoteApprovalRequestStatus;
use App\Models\QuoteApprovalRequest;
use App\Models\QuoteRevision;

/**
 * The rules that later approval services must honour when a revision's financial
 * content changes, expressed as callable helpers rather than prose.
 *
 * The policy is:
 *
 * 1. A draft revision may change freely, but any pending approval request for it
 *    stops describing the quote that was submitted, so it is superseded rather
 *    than left open. Superseding also frees the one-pending-per-revision slot.
 * 2. An approval already granted describes the numbers that existed when it was
 *    granted. Changing those numbers invalidates the approval, and because a
 *    customer-visible revision is immutable, the change has to happen on a new
 *    draft revision that carries its own approval.
 * 3. Nothing here cancels or rewrites decisions. Decisions are append-only; only
 *    the request status moves.
 *
 * Sending, PDF generation, and notification are out of scope for 2C.1.
 */
class QuoteApprovalInvalidationContract
{
    /**
     * @throws ImmutableQuoteRevisionException when the revision is customer-visible
     */
    public function assertMayMutateFinancialContent(QuoteRevision $revision): void
    {
        QuoteRevisionTaxGuard::assertMayMutateFinancialContent($revision);
    }

    /**
     * Supersede every pending request on a still-mutable revision.
     *
     * @return int number of requests superseded
     *
     * @throws ImmutableQuoteRevisionException when the revision is customer-visible
     */
    public function markPendingRequestsSuperseded(QuoteRevision $revision): int
    {
        $this->assertMayMutateFinancialContent($revision);

        $pending = QuoteApprovalRequest::query()
            ->where('quote_revision_id', $revision->id)
            ->where('status', QuoteApprovalRequestStatus::Pending)
            ->get();

        foreach ($pending as $request) {
            $request->update([
                'status' => QuoteApprovalRequestStatus::Superseded,
                'resolved_at' => now(),
            ]);
        }

        return $pending->count();
    }
}
