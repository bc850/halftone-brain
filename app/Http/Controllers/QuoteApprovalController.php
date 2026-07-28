<?php

namespace App\Http\Controllers;

use App\Enums\QuoteRevisionStatus;
use App\Http\Controllers\Concerns\HandlesQuoteDrafts;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Requests\EvaluateQuoteApprovalRequest;
use App\Http\Requests\QuoteApprovalTransitionRequest;
use App\Http\Requests\SubmitQuoteApprovalRequest;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Support\Quotes\Approval\QuoteApprovalWorkflowService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Moving a revision through the approval gate.
 *
 * Evaluation is deliberately separate from submission: a salesperson can ask what
 * would happen before committing, and the answer they get is produced by the same
 * evaluator that will run at submission time.
 */
class QuoteApprovalController extends Controller
{
    use HandlesQuoteDrafts;
    use RequiresTenantContext;

    public function __construct(private QuoteApprovalWorkflowService $workflow) {}

    /**
     * A dry run. Nothing about the revision changes.
     */
    public function evaluate(
        EvaluateQuoteApprovalRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision, 'update');

        $this->runDraftMutation(fn () => $this->workflow->evaluate(
            quote: $quote,
            revision: $quoteRevision,
            manualEscalation: $request->manualEscalation(),
            actor: $request->user(),
        ));

        return $this->done(__('Approval requirements reviewed.'));
    }

    public function submit(
        SubmitQuoteApprovalRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision, 'update');

        $revision = $this->runDraftMutation(fn (): QuoteRevision => $this->workflow->submitForApproval(
            quote: $quote,
            revision: $quoteRevision,
            expectedQuoteLockVersion: $request->expectedQuoteLockVersion(),
            expectedRevisionLockVersion: $request->expectedLockVersion(),
            actor: $request->user(),
            actorMembership: $this->actingMembership(),
            manualEscalation: $request->manualEscalation(),
        ));

        return $this->done($revision->status === QuoteRevisionStatus::Approved
            ? __('Quote approved automatically; no approval was required.')
            : __('Quote submitted for approval.'));
    }

    public function withdraw(
        QuoteApprovalTransitionRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision, 'update');

        $this->runDraftMutation(fn (): QuoteRevision => $this->workflow->withdrawToDraft(
            quote: $quote,
            revision: $quoteRevision,
            expectedQuoteLockVersion: $request->expectedQuoteLockVersion(),
            expectedRevisionLockVersion: $request->expectedLockVersion(),
            actor: $request->user(),
            actorMembership: $this->actingMembership(),
        ));

        return $this->done(__('Approval request withdrawn.'));
    }

    /**
     * Reopening an approved revision. Sending has not happened yet, so the approval
     * is simply discarded and the revision becomes editable again.
     */
    public function returnToDraft(
        QuoteApprovalTransitionRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision, 'update');

        $this->runDraftMutation(fn (): QuoteRevision => $this->workflow->returnApprovedToDraft(
            quote: $quote,
            revision: $quoteRevision,
            expectedQuoteLockVersion: $request->expectedQuoteLockVersion(),
            expectedRevisionLockVersion: $request->expectedLockVersion(),
            actor: $request->user(),
            actorMembership: $this->actingMembership(),
        ));

        return $this->done(__('Revision returned to draft.'));
    }

    private function prepare(Quote $quote, QuoteRevision $revision, string $ability): void
    {
        $this->requireTenantContext();
        $this->authorize($ability, $quote);
        $this->assertRevisionBelongsToQuote($quote, $revision);
    }

    private function actingMembership(): Membership
    {
        return Membership::query()->findOrFail($this->requireTenantContext()->organizationMembershipId);
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
