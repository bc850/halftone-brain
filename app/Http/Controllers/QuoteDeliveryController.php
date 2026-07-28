<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsQuoteDeliveryPanel;
use App\Http\Controllers\Concerns\BuildsQuoteTaxAndApprovalPanels;
use App\Http\Controllers\Concerns\HandlesQuoteDrafts;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Requests\RecordManualQuoteDeliveryRequest;
use App\Http\Resources\QuoteResource;
use App\Http\Resources\QuoteRevisionResource;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\QuoteCustomerAccessToken;
use App\Models\QuoteDelivery;
use App\Models\QuoteRevision;
use App\Models\User;
use App\Support\Quotes\Delivery\QuoteManualDeliveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QuoteDeliveryController extends Controller
{
    use BuildsQuoteDeliveryPanel;
    use BuildsQuoteTaxAndApprovalPanels;
    use HandlesQuoteDrafts;
    use RequiresTenantContext;

    public function __construct(private QuoteManualDeliveryService $manualDelivery) {}

    public function show(
        Request $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): Response {
        $tenant = $this->requireTenantContext();
        $this->authorize('view', $quote);
        $this->assertRevisionBelongsToQuote($quote, $quoteRevision);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('quotes/DeliveryHistory', [
            'quote' => QuoteResource::make($quote, $user),
            'revision' => QuoteRevisionResource::make(
                $quoteRevision,
                $tenant->canViewCost(),
                QuoteRevisionController::liveCatalogFor($quoteRevision),
            ),
            'delivery' => $this->deliveryPanel($quote, $quoteRevision, $user),
            'canUpdate' => $user->can('update', $quote),
        ]);
    }

    public function recordManual(
        RecordManualQuoteDeliveryRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
        QuoteDelivery $delivery,
    ): RedirectResponse {
        $this->requireTenantContext();
        $this->authorize('send', $quote);
        $this->assertRevisionBelongsToQuote($quote, $quoteRevision);

        /** @var QuoteCustomerAccessToken $token */
        $token = QuoteCustomerAccessToken::query()
            ->whereKey($request->customerAccessTokenId())
            ->where('quote_revision_id', $quoteRevision->id)
            ->where('organization_id', $quote->organization_id)
            ->firstOrFail();

        $this->runDraftMutation(fn (): QuoteDelivery => $this->manualDelivery->recordManualSend(
            quote: $quote,
            revision: $quoteRevision,
            delivery: $delivery,
            token: $token,
            expectedQuoteLockVersion: $request->expectedQuoteLockVersion(),
            expectedRevisionLockVersion: $request->expectedLockVersion(),
            recipientName: $request->recipientName(),
            recipientEmail: $request->recipientEmail(),
            confirmed: $request->confirmed(),
            actorMembership: $this->actingMembership(),
            actor: $request->user(),
            externalReference: $request->externalReference(),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Manual delivery recorded.')]);

        return back();
    }

    private function actingMembership(): Membership
    {
        return Membership::query()->findOrFail($this->requireTenantContext()->organizationMembershipId);
    }
}
