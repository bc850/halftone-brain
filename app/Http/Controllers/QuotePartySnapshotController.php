<?php

namespace App\Http\Controllers;

use App\Enums\QuoteRevisionStatus;
use App\Http\Controllers\Concerns\HandlesQuoteDrafts;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Requests\RefreshQuotePartySnapshotRequest;
use App\Http\Requests\UpdateQuotePartySnapshotRequest;
use App\Http\Resources\QuotePartySnapshotResource;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionPartySnapshot;
use App\Support\Quotes\QuotePartySnapshotService;
use App\Support\Tenancy\TenantRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QuotePartySnapshotController extends Controller
{
    use HandlesQuoteDrafts;
    use RequiresTenantContext;

    public function __construct(private QuotePartySnapshotService $snapshots) {}

    public function edit(
        Request $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): Response {
        $this->prepare($quote, $quoteRevision);

        abort_unless(
            $quoteRevision->status === QuoteRevisionStatus::Draft,
            409,
            'Only draft revisions can be edited.',
        );

        $quoteRevision->loadMissing('partySnapshot');
        $snapshot = $quoteRevision->partySnapshot;

        return Inertia::render('quotes/PartyEdit', [
            'quote' => [
                'id' => $quote->id,
                'quote_number' => $quote->quote_number,
            ],
            'revision' => [
                'id' => $quoteRevision->id,
                'revision_number' => $quoteRevision->revision_number,
                'lock_version' => $quoteRevision->lock_version,
            ],
            'snapshot' => QuotePartySnapshotResource::make($snapshot),
            'contacts' => $snapshot === null ? [] : Contact::query()
                ->where('company_id', $snapshot->company_id)
                ->orderByDesc('is_primary')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn (Contact $contact): array => [
                    'id' => $contact->id,
                    'name' => trim($contact->first_name.' '.$contact->last_name),
                ])
                ->values()
                ->all(),
            'builderUrl' => TenantRoute::to('quotes.revisions.edit', [
                'quote' => $quote,
                'quoteRevision' => $quoteRevision,
            ]),
        ]);
    }

    public function update(
        UpdateQuotePartySnapshotRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision);

        $this->runDraftMutation(fn (): QuoteRevisionPartySnapshot => $this->snapshots->updateDraft(
            quote: $quote,
            revision: $quoteRevision,
            expectedLockVersion: $request->expectedLockVersion(),
            data: $request->snapshotChanges(),
            actor: $request->user(),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer details updated.')]);

        return back();
    }

    /**
     * Read-only drift report. The user decides whether to accept it.
     */
    public function refreshPreview(
        Request $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision);

        $preview = $this->runDraftMutation(fn (): array => $this->snapshots->previewRefresh($quoteRevision));

        Inertia::flash('quotePartyRefreshPreview', $preview);

        return back();
    }

    public function refresh(
        RefreshQuotePartySnapshotRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision);

        $this->runDraftMutation(fn (): QuoteRevisionPartySnapshot => $this->snapshots->refreshFromCustomer(
            quote: $quote,
            revision: $quoteRevision,
            expectedRevisionLockVersion: $request->expectedLockVersion(),
            actor: $request->user(),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer details refreshed from CRM.')]);

        return back();
    }

    private function prepare(Quote $quote, QuoteRevision $revision): void
    {
        $this->requireTenantContext();
        $this->authorize('update', $quote);
        $this->assertRevisionBelongsToQuote($quote, $revision);
    }
}
