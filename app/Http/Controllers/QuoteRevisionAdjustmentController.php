<?php

namespace App\Http\Controllers;

use App\Enums\QuoteAdjustmentType;
use App\Http\Controllers\Concerns\HandlesQuoteDrafts;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Requests\QuoteDraftLockVersionRequest;
use App\Http\Requests\StoreQuoteAdjustmentRequest;
use App\Http\Requests\UpdateQuoteAdjustmentRequest;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionAdjustment;
use App\Support\Quotes\QuoteDraftAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class QuoteRevisionAdjustmentController extends Controller
{
    use HandlesQuoteDrafts;
    use RequiresTenantContext;

    public function __construct(private QuoteDraftAdjustmentService $adjustments) {}

    public function store(
        StoreQuoteAdjustmentRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision);

        $type = $request->adjustmentType();

        if ($type->isDiscount()) {
            $this->requireOverrideAuthority();
        }

        $this->runDraftMutation(fn (): QuoteRevisionAdjustment => $this->adjustments->add(
            quote: $quote,
            revision: $quoteRevision,
            expectedRevisionLockVersion: $request->expectedLockVersion(),
            adjustmentType: $type,
            description: (string) $request->validated('description'),
            method: $request->adjustmentMethod(),
            inputValue: $request->inputValue(),
            isTaxable: $request->boolean('is_taxable'),
            reason: $request->validated('reason'),
            mayOverride: $this->mayOverridePrice(),
            actor: $request->user(),
        ));

        return $this->done(__('Adjustment added.'));
    }

    public function update(
        UpdateQuoteAdjustmentRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
        QuoteRevisionAdjustment $adjustment,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision);

        if ($adjustment->adjustment_type === QuoteAdjustmentType::QuoteDiscount) {
            $this->requireOverrideAuthority();
        }

        $this->runDraftMutation(fn (): QuoteRevisionAdjustment => $this->adjustments->update(
            quote: $quote,
            revision: $quoteRevision,
            expectedRevisionLockVersion: $request->expectedLockVersion(),
            adjustment: $adjustment,
            data: $request->adjustmentChanges(),
            mayOverride: $this->mayOverridePrice(),
            actor: $request->user(),
        ));

        return $this->done(__('Adjustment updated.'));
    }

    public function destroy(
        QuoteDraftLockVersionRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
        QuoteRevisionAdjustment $adjustment,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision);

        $this->runDraftMutation(function () use ($request, $quote, $quoteRevision, $adjustment): void {
            $this->adjustments->remove(
                quote: $quote,
                revision: $quoteRevision,
                expectedRevisionLockVersion: $request->expectedLockVersion(),
                adjustment: $adjustment,
                actor: $request->user(),
            );
        });

        return $this->done(__('Adjustment removed.'));
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
