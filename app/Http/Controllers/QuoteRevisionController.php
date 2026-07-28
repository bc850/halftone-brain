<?php

namespace App\Http\Controllers;

use App\Enums\QuoteRevisionStatus;
use App\Enums\UnitOfMeasure;
use App\Http\Controllers\Concerns\BuildsQuoteDeliveryPanel;
use App\Http\Controllers\Concerns\BuildsQuoteTaxAndApprovalPanels;
use App\Http\Controllers\Concerns\HandlesQuoteDrafts;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Requests\CloneQuoteRevisionRequest;
use App\Http\Requests\UpdateQuoteRevisionContentRequest;
use App\Http\Resources\QuoteResource;
use App\Http\Resources\QuoteRevisionResource;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\User;
use App\Support\Catalog\CatalogQuoteOptions;
use App\Support\Quotes\QuotePartySnapshotService;
use App\Support\Quotes\QuoteRevisionCloner;
use App\Support\Tenancy\TenantRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QuoteRevisionController extends Controller
{
    use BuildsQuoteDeliveryPanel;
    use BuildsQuoteTaxAndApprovalPanels;
    use HandlesQuoteDrafts;
    use RequiresTenantContext;

    public function __construct(
        private QuotePartySnapshotService $partySnapshots,
        private QuoteRevisionCloner $cloner,
    ) {}

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
        $canViewCost = $tenant->canViewCost();

        return Inertia::render('quotes/Revision', [
            'quote' => QuoteResource::make($quote, $user),
            'revision' => QuoteRevisionResource::make(
                $quoteRevision,
                $canViewCost,
                self::liveCatalogFor($quoteRevision),
            ),
            'canViewCost' => $canViewCost,
            'canUpdate' => $user->can('update', $quote),
            'tax' => $this->taxPanel($quote, $quoteRevision, $user),
            'approval' => $this->approvalPanel($quote, $quoteRevision, $user),
            'delivery' => $this->deliveryPanel($quote, $quoteRevision, $user),
            'quoteUrl' => TenantRoute::to('quotes.show', $quote),
        ]);
    }

    public function edit(
        Request $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): Response {
        $tenant = $this->requireTenantContext();
        $this->authorize('update', $quote);
        $this->assertRevisionBelongsToQuote($quote, $quoteRevision);

        abort_unless(
            $quoteRevision->status === QuoteRevisionStatus::Draft,
            409,
            'Only draft revisions can be edited.',
        );

        /** @var User $user */
        $user = $request->user();
        $canViewCost = $tenant->canViewCost();

        return Inertia::render('quotes/Builder', [
            'quote' => QuoteResource::make($quote, $user),
            'revision' => QuoteRevisionResource::make(
                $quoteRevision,
                $canViewCost,
                self::liveCatalogFor($quoteRevision),
            ),
            'catalog' => CatalogQuoteOptions::sellable(
                $tenant,
                $request->string('catalog_search')->toString(),
            ),
            'catalogSearch' => $request->string('catalog_search')->toString(),
            'unitOfMeasureOptions' => array_map(
                fn (UnitOfMeasure $uom): array => ['value' => $uom->value, 'label' => $uom->label()],
                UnitOfMeasure::cases(),
            ),
            'canViewCost' => $canViewCost,
            'canOverridePrice' => $this->mayOverridePrice(),
            'canApproveBelowMinimum' => $this->mayApproveBelowMinimum(),
            'tax' => $this->taxPanel($quote, $quoteRevision, $user),
            'approval' => $this->approvalPanel($quote, $quoteRevision, $user),
            'partyEditUrl' => TenantRoute::to('quotes.revisions.party.edit', [
                'quote' => $quote,
                'quoteRevision' => $quoteRevision,
            ]),
            'quoteUrl' => TenantRoute::to('quotes.show', $quote),
        ]);
    }

    public function updateContent(
        UpdateQuoteRevisionContentRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): RedirectResponse {
        $this->requireTenantContext();
        $this->authorize('update', $quote);
        $this->assertRevisionBelongsToQuote($quote, $quoteRevision);

        $this->runDraftMutation(fn (): QuoteRevision => $this->partySnapshots->updateRevisionContent(
            quote: $quote,
            revision: $quoteRevision,
            expectedLockVersion: $request->expectedLockVersion(),
            data: $request->contentChanges(),
            actor: $request->user(),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Quote content updated.')]);

        return back();
    }

    public function clone(
        CloneQuoteRevisionRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): RedirectResponse {
        $this->requireTenantContext();
        $this->authorize('update', $quote);
        $this->assertRevisionBelongsToQuote($quote, $quoteRevision);

        $clone = $this->runDraftMutation(fn (): QuoteRevision => $this->cloner->cloneToDraft(
            quote: $quote,
            source: $quoteRevision,
            expectedQuoteLockVersion: $request->expectedLockVersion(),
            actor: $request->user(),
        ));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Revision :number created.', ['number' => $clone->revision_number]),
        ]);

        return redirect()->to(TenantRoute::to('quotes.revisions.edit', [
            'quote' => $quote,
            'quoteRevision' => $clone,
        ]));
    }

    /**
     * Live catalog rows for the revision's catalog lines, used to flag stale pricing snapshots.
     *
     * @return array<int, OrganizationProduct>
     */
    public static function liveCatalogFor(QuoteRevision $revision): array
    {
        $revision->loadMissing('lineItems');

        $ids = $revision->lineItems
            ->pluck('organization_product_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return OrganizationProduct::query()
            ->whereKey($ids)
            ->where('organization_id', $revision->organization_id)
            ->get()
            ->keyBy('id')
            ->all();
    }
}
