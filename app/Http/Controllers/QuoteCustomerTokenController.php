<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesQuoteDrafts;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Requests\RegenerateQuoteCustomerTokenRequest;
use App\Http\Requests\RevokeQuoteCustomerTokenRequest;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\QuoteCustomerAccessToken;
use App\Models\QuoteRevision;
use App\Support\Quotes\Delivery\QuoteCustomerLinkPreparationResult;
use App\Support\Quotes\Token\QuoteCustomerTokenLifecycleService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class QuoteCustomerTokenController extends Controller
{
    use HandlesQuoteDrafts;
    use RequiresTenantContext;

    public function __construct(private QuoteCustomerTokenLifecycleService $lifecycle) {}

    public function revoke(
        RevokeQuoteCustomerTokenRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
        QuoteCustomerAccessToken $customerAccessToken,
    ): RedirectResponse {
        $this->requireTenantContext();
        $this->authorize('send', $quote);
        $this->assertRevisionBelongsToQuote($quote, $quoteRevision);

        $this->runDraftMutation(fn (): QuoteCustomerAccessToken => $this->lifecycle->revoke(
            token: $customerAccessToken,
            reason: $request->reason(),
            actor: $request->user(),
            actorMembership: $this->actingMembership(),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer link revoked.')]);

        return back();
    }

    public function regenerate(
        RegenerateQuoteCustomerTokenRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): Response {
        $this->requireTenantContext();
        $this->authorize('send', $quote);
        $this->assertRevisionBelongsToQuote($quote, $quoteRevision);

        $prepared = $this->runDraftMutation(
            fn (): QuoteCustomerLinkPreparationResult => $this->lifecycle->regenerate(
                quote: $quote,
                revision: $quoteRevision,
                actorMembership: $this->actingMembership(),
                actor: $request->user(),
                recipientName: $request->recipientName(),
                recipientEmail: $request->recipientEmail(),
            )
        );

        return QuoteCustomerLinkController::customerLinkReadyResponse($quote, $quoteRevision, $prepared);
    }

    private function actingMembership(): Membership
    {
        return Membership::query()->findOrFail($this->requireTenantContext()->organizationMembershipId);
    }
}
