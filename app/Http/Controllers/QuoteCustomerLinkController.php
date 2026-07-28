<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesQuoteDrafts;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Requests\PrepareQuoteCustomerLinkRequest;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Support\Quotes\Delivery\QuoteCustomerLinkPreparationResult;
use App\Support\Quotes\Delivery\QuoteCustomerLinkPreparationService;
use App\Support\Tenancy\TenantRoute;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Prepares a customer access link and returns the raw URL once via Inertia props.
 *
 * The raw token/URL must never be flashed to session for a later request. The
 * CustomerLinkReady page is only reachable from this redirect response; a later
 * visit to the delivery/revision page does not re-expose the URL.
 */
class QuoteCustomerLinkController extends Controller
{
    use HandlesQuoteDrafts;
    use RequiresTenantContext;

    public function __construct(private QuoteCustomerLinkPreparationService $links) {}

    public function prepare(
        PrepareQuoteCustomerLinkRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): Response {
        $this->requireTenantContext();
        $this->authorize('send', $quote);
        $this->assertRevisionBelongsToQuote($quote, $quoteRevision);

        $prepared = $this->runDraftMutation(
            fn (): QuoteCustomerLinkPreparationResult => $this->links->prepare(
                quote: $quote,
                revision: $quoteRevision,
                actorMembership: $this->actingMembership(),
                actor: $request->user(),
                recipientName: $request->recipientName(),
                recipientEmail: $request->recipientEmail(),
            )
        );

        return $this->customerLinkReady($quote, $quoteRevision, $prepared);
    }

    /**
     * Render the one-request CustomerLinkReady page. Do not put customer_url in
     * flash/session — subsequent Inertia shared props must not re-expose it.
     */
    public static function customerLinkReadyResponse(
        Quote $quote,
        QuoteRevision $revision,
        QuoteCustomerLinkPreparationResult $prepared,
    ): Response {
        return Inertia::render('quotes/CustomerLinkReady', [
            // One-request prop only: present on this Inertia::render response and
            // not stored for later reloads. Navigating away and back cannot recover it.
            'customer_url' => $prepared->rawCustomerUrl,
            'token_id' => $prepared->tokenId,
            'delivery_id' => $prepared->deliveryId,
            'document_id' => $prepared->documentId,
            'expires_at' => $prepared->expiresAt,
            'quote_id' => $quote->id,
            'revision_id' => $revision->id,
            'quote_number' => $quote->quote_number,
            'revision_number' => $revision->revision_number,
            'delivery_url' => TenantRoute::to('quotes.revisions.delivery', [
                'quote' => $quote,
                'quoteRevision' => $revision,
            ]),
            'revision_url' => TenantRoute::to('quotes.revisions.show', [
                'quote' => $quote,
                'quoteRevision' => $revision,
            ]),
        ]);
    }

    private function customerLinkReady(
        Quote $quote,
        QuoteRevision $revision,
        QuoteCustomerLinkPreparationResult $prepared,
    ): Response {
        return self::customerLinkReadyResponse($quote, $revision, $prepared);
    }

    private function actingMembership(): Membership
    {
        return Membership::query()->findOrFail($this->requireTenantContext()->organizationMembershipId);
    }
}
