<?php

namespace App\Http\Controllers;

use App\Enums\QuoteLineType;
use App\Http\Controllers\Concerns\HandlesQuoteDrafts;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Requests\QuoteDraftLockVersionRequest;
use App\Http\Requests\ReorderQuoteLinesRequest;
use App\Http\Requests\StoreQuoteCatalogLineRequest;
use App\Http\Requests\StoreQuoteCustomLineRequest;
use App\Http\Requests\StoreQuotePresentationLineRequest;
use App\Http\Requests\UpdateQuoteLineRequest;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionLineItem;
use App\Support\Quotes\QuoteDraftLineService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class QuoteRevisionLineController extends Controller
{
    use HandlesQuoteDrafts;
    use RequiresTenantContext;

    public function __construct(private QuoteDraftLineService $lines) {}

    public function storeCatalog(
        StoreQuoteCatalogLineRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision);

        $organizationProduct = OrganizationProduct::query()
            ->with('product')
            ->findOrFail((int) $request->validated('organization_product_id'));

        $overrideCents = $request->overrideUnitPriceCents();

        if ($overrideCents !== null) {
            $this->requireOverrideAuthority();
            $this->requireBelowMinimumAuthority($organizationProduct->minimum_price_cents, $overrideCents);
        }

        $this->runDraftMutation(fn (): QuoteRevisionLineItem => $this->lines->addCatalogLine(
            quote: $quote,
            revision: $quoteRevision,
            expectedRevisionLockVersion: $request->expectedLockVersion(),
            organizationProduct: $organizationProduct,
            quantity: (string) $request->validated('quantity'),
            overrideUnitPriceCents: $overrideCents,
            overrideReason: $request->validated('override_reason'),
            isTaxable: $request->boolean('is_taxable', true),
            customerDescription: $request->validated('customer_description'),
            internalDescription: $request->validated('internal_description'),
            mayOverride: $this->mayOverridePrice(),
            actor: $request->user(),
        ));

        return $this->done(__('Line added.'));
    }

    public function storeCustom(
        StoreQuoteCustomLineRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision);
        $this->requireOverrideAuthority();

        $this->runDraftMutation(fn (): QuoteRevisionLineItem => $this->lines->addCustomLine(
            quote: $quote,
            revision: $quoteRevision,
            expectedRevisionLockVersion: $request->expectedLockVersion(),
            name: (string) $request->validated('name'),
            quantity: (string) $request->validated('quantity'),
            unitPriceCents: $request->unitPriceCents(),
            reason: (string) $request->validated('reason'),
            customerDescription: $request->validated('customer_description'),
            internalDescription: $request->validated('internal_description'),
            uom: $request->validated('uom'),
            isTaxable: $request->boolean('is_taxable', true),
            mayOverride: true,
            actor: $request->user(),
        ));

        return $this->done(__('Custom line added.'));
    }

    public function storeSection(
        StoreQuotePresentationLineRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision);

        $this->runDraftMutation(fn (): QuoteRevisionLineItem => $this->lines->addSectionLine(
            quote: $quote,
            revision: $quoteRevision,
            expectedRevisionLockVersion: $request->expectedLockVersion(),
            name: (string) $request->validated('name'),
            customerDescription: $request->validated('customer_description'),
            actor: $request->user(),
        ));

        return $this->done(__('Section added.'));
    }

    public function storeNote(
        StoreQuotePresentationLineRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision);

        $this->runDraftMutation(fn (): QuoteRevisionLineItem => $this->lines->addNoteLine(
            quote: $quote,
            revision: $quoteRevision,
            expectedRevisionLockVersion: $request->expectedLockVersion(),
            name: (string) $request->validated('name'),
            customerDescription: $request->validated('customer_description'),
            actor: $request->user(),
        ));

        return $this->done(__('Note added.'));
    }

    public function update(
        UpdateQuoteLineRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
        QuoteRevisionLineItem $line,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision);

        $requestedPriceCents = $request->requestedUnitPriceCents();

        if ($requestedPriceCents !== null && $this->isPriceChange($line, $requestedPriceCents)) {
            $this->requireOverrideAuthority();
            $this->requireBelowMinimumAuthority($this->snapshotMinimumCents($line), $requestedPriceCents);
        }

        $this->runDraftMutation(fn (): QuoteRevisionLineItem => $this->lines->updateLine(
            quote: $quote,
            revision: $quoteRevision,
            expectedRevisionLockVersion: $request->expectedLockVersion(),
            line: $line,
            data: $request->lineChanges(),
            mayOverride: $this->mayOverridePrice(),
            actor: $request->user(),
        ));

        return $this->done(__('Line updated.'));
    }

    public function destroy(
        QuoteDraftLockVersionRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
        QuoteRevisionLineItem $line,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision);

        $this->runDraftMutation(function () use ($request, $quote, $quoteRevision, $line): void {
            $this->lines->removeLine(
                quote: $quote,
                revision: $quoteRevision,
                expectedRevisionLockVersion: $request->expectedLockVersion(),
                line: $line,
                actor: $request->user(),
            );
        });

        return $this->done(__('Line removed.'));
    }

    public function reorder(
        ReorderQuoteLinesRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision);

        $this->runDraftMutation(fn () => $this->lines->reorderLines(
            $quote,
            $quoteRevision,
            $request->expectedLockVersion(),
            $request->orderedLineIds(),
            $request->user(),
        ));

        return $this->done(__('Lines reordered.'));
    }

    private function isPriceChange(QuoteRevisionLineItem $line, int $requestedPriceCents): bool
    {
        return $line->line_type === QuoteLineType::Custom
            || $requestedPriceCents !== $line->calculated_unit_price_cents;
    }

    private function snapshotMinimumCents(QuoteRevisionLineItem $line): ?int
    {
        $minimum = $line->pricing_input_snapshot_json['minimum_price_cents'] ?? null;

        return is_int($minimum) ? $minimum : null;
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
