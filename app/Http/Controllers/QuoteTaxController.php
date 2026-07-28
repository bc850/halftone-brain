<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesQuoteDrafts;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Requests\CalculateQuoteTaxRequest;
use App\Http\Requests\OverrideQuoteTaxRequest;
use App\Http\Resources\TaxCalculationResource;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionTaxCalculation;
use App\Support\Quotes\Tax\QuoteTaxCalculationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Resolving the tax position of a draft revision.
 *
 * Every run appends a new calculation version rather than editing the last one, so
 * the panel that shows "the" tax figure is really showing the newest of a history
 * this controller can also serve in full.
 */
class QuoteTaxController extends Controller
{
    use HandlesQuoteDrafts;
    use RequiresTenantContext;

    public function __construct(private QuoteTaxCalculationService $tax) {}

    public function calculate(
        CalculateQuoteTaxRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision, 'calculateTax');

        $calculation = $this->runDraftMutation(
            fn (): QuoteRevisionTaxCalculation => $this->tax->calculate(
                quote: $quote,
                revision: $quoteRevision,
                expectedLockVersion: $request->expectedLockVersion(),
                organizationTaxRateId: $request->organizationTaxRateId(),
                certificateId: $request->certificateId(),
                actor: $request->user(),
                actorMembership: $this->actingMembership(),
            )
        );

        return $this->done(match ($calculation->outcome->value) {
            'exempt' => __('Tax resolved as exempt.'),
            'review_required' => __('Tax needs review before this quote can be approved.'),
            default => __('Tax calculated.'),
        });
    }

    public function override(
        OverrideQuoteTaxRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision, 'overrideTax');

        $this->runDraftMutation(fn (): QuoteRevisionTaxCalculation => $this->tax->override(
            quote: $quote,
            revision: $quoteRevision,
            expectedLockVersion: $request->expectedLockVersion(),
            overrideTax: $request->overrideTax(),
            reason: $request->reason(),
            organizationTaxRateId: $request->organizationTaxRateId(),
            actor: $request->user(),
            actorMembership: $this->actingMembership(),
        ));

        return $this->done(__('Manual tax override recorded.'));
    }

    /**
     * The full append-only history behind the current figure.
     */
    public function history(
        Request $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): JsonResponse {
        $this->prepare($quote, $quoteRevision, 'calculateTax');

        return new JsonResponse([
            'history' => TaxCalculationResource::collection(self::historyFor($quoteRevision)),
        ]);
    }

    /**
     * Newest first, so the panel can show the current figure without re-sorting.
     *
     * @return Collection<int, QuoteRevisionTaxCalculation>
     */
    public static function historyFor(QuoteRevision $revision): Collection
    {
        return QuoteRevisionTaxCalculation::query()
            ->where('quote_revision_id', $revision->id)
            ->orderByDesc('calculation_version')
            ->get();
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
