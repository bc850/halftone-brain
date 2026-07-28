<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesQuoteDrafts;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Requests\AcceptQuoteAsEmployeeRequest;
use App\Http\Requests\RejectQuoteAsEmployeeRequest;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\QuoteCustomerAccessToken;
use App\Models\QuoteCustomerResponseEvent;
use App\Models\QuoteRevision;
use App\Support\Quotes\Acceptance\QuoteCustomerResponseService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class QuoteEmployeeResponseController extends Controller
{
    use HandlesQuoteDrafts;
    use RequiresTenantContext;

    public function __construct(private QuoteCustomerResponseService $responses) {}

    public function accept(
        AcceptQuoteAsEmployeeRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision);
        $token = $this->tokenFor($quote, $quoteRevision, $request->customerAccessTokenId());

        $this->runDraftMutation(fn (): QuoteCustomerResponseEvent => $this->responses->acceptAsEmployee(
            quote: $quote,
            revision: $quoteRevision,
            token: $token,
            expectedQuoteLockVersion: $request->expectedQuoteLockVersion(),
            expectedRevisionLockVersion: $request->expectedLockVersion(),
            typedName: $request->typedName(),
            termsAccepted: $request->termsAccepted(),
            employeeRecordedReason: $request->employeeRecordedReason(),
            employeeMembership: $this->actingMembership(),
            employeeUser: $request->user(),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer acceptance recorded.')]);

        return back();
    }

    public function reject(
        RejectQuoteAsEmployeeRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision);
        $token = $this->tokenFor($quote, $quoteRevision, $request->customerAccessTokenId());

        $this->runDraftMutation(fn (): QuoteCustomerResponseEvent => $this->responses->rejectAsEmployee(
            quote: $quote,
            revision: $quoteRevision,
            token: $token,
            expectedQuoteLockVersion: $request->expectedQuoteLockVersion(),
            expectedRevisionLockVersion: $request->expectedLockVersion(),
            typedName: $request->typedName(),
            employeeRecordedReason: $request->employeeRecordedReason(),
            employeeMembership: $this->actingMembership(),
            employeeUser: $request->user(),
            rejectionReason: $request->rejectionReason(),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer rejection recorded.')]);

        return back();
    }

    private function prepare(Quote $quote, QuoteRevision $revision): void
    {
        $this->requireTenantContext();
        $this->authorize('recordCustomerResponse', $quote);
        $this->assertRevisionBelongsToQuote($quote, $revision);
    }

    private function tokenFor(Quote $quote, QuoteRevision $revision, int $tokenId): QuoteCustomerAccessToken
    {
        return QuoteCustomerAccessToken::query()
            ->whereKey($tokenId)
            ->where('quote_revision_id', $revision->id)
            ->where('organization_id', $quote->organization_id)
            ->firstOrFail();
    }

    private function actingMembership(): Membership
    {
        return Membership::query()->findOrFail($this->requireTenantContext()->organizationMembershipId);
    }
}
