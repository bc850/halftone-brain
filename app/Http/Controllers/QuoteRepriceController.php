<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesQuoteDrafts;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Requests\QuoteDraftLockVersionRequest;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionLineItem;
use App\Support\Quotes\QuoteRepriceService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class QuoteRepriceController extends Controller
{
    use HandlesQuoteDrafts;
    use RequiresTenantContext;

    public function __construct(private QuoteRepriceService $reprice) {}

    public function repriceLine(
        QuoteDraftLockVersionRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
        QuoteRevisionLineItem $line,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision);

        $this->runDraftMutation(fn (): QuoteRevisionLineItem => $this->reprice->repriceLine(
            quote: $quote,
            revision: $quoteRevision,
            expectedRevisionLockVersion: $request->expectedLockVersion(),
            line: $line,
            preserveOverride: $request->preserveOverride(),
            actor: $request->user(),
        ));

        return $this->done(__('Line repriced against the current catalog.'));
    }

    public function repriceCatalog(
        QuoteDraftLockVersionRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision);

        $this->runDraftMutation(fn (): array => $this->reprice->repriceAllCatalogLines(
            quote: $quote,
            revision: $quoteRevision,
            expectedRevisionLockVersion: $request->expectedLockVersion(),
            preserveOverride: $request->preserveOverride(),
            actor: $request->user(),
        ));

        return $this->done(__('Catalog lines repriced.'));
    }

    /**
     * Dropping an override restores the catalog calculated price, so it needs no
     * override authority — only the ability to edit the draft.
     */
    public function resetOverride(
        QuoteDraftLockVersionRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
        QuoteRevisionLineItem $line,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision);

        $this->runDraftMutation(fn (): QuoteRevisionLineItem => $this->reprice->resetOverrideToCalculated(
            quote: $quote,
            revision: $quoteRevision,
            expectedRevisionLockVersion: $request->expectedLockVersion(),
            line: $line,
            actor: $request->user(),
        ));

        return $this->done(__('Override reset to the catalog price.'));
    }

    private function prepare(Quote $quote, QuoteRevision $revision): void
    {
        $this->requireTenantContext();
        $this->authorize('update', $quote);
        $this->assertRevisionBelongsToQuote($quote, $revision);
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
