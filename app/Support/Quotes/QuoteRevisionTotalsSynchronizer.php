<?php

namespace App\Support\Quotes;

use App\Enums\QuoteTaxCalculationStatus;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionAdjustment;
use App\Models\QuoteRevisionLineItem;
use App\Models\User;
use App\Support\Quotes\Totals\QuoteAdjustmentCalculationInput;
use App\Support\Quotes\Totals\QuoteLineCalculationInput;
use App\Support\Quotes\Totals\QuoteTotalsCalculator;
use App\Support\Quotes\Totals\QuoteTotalsResult;
use Illuminate\Database\Eloquent\Collection;

/**
 * Recomputes persisted line money and revision totals from the pure calculator.
 *
 * Does not bump `lock_version` and does not audit — the calling draft mutator owns both
 * so a single user action produces exactly one version bump and one audit entry.
 */
final class QuoteRevisionTotalsSynchronizer
{
    public function __construct(
        private QuoteTotalsCalculator $calculator,
        private QuoteApprovalReasonAggregator $approvalReasons,
    ) {}

    /**
     * @param  User|null  $actor  Reserved for future approval-context resolution; totals themselves are actor independent.
     */
    public function sync(QuoteRevision $revision, ?User $actor = null): QuoteTotalsResult
    {
        unset($actor);

        $lines = $this->lines($revision);
        $adjustments = $this->adjustments($revision);

        $lineInputs = [];
        foreach ($lines as $line) {
            $lineInputs[] = $this->toLineInput($line);
        }

        $adjustmentInputs = [];
        foreach ($adjustments as $adjustment) {
            $adjustmentInputs[] = $this->toAdjustmentInput($adjustment);
        }

        $totals = $this->calculator->calculate($lineInputs, $adjustmentInputs);

        $linesByKey = $lines->keyBy(fn (QuoteRevisionLineItem $line): string => (string) $line->id);

        foreach ($totals->lines as $result) {
            $line = $linesByKey->get($result->key);
            if (! $line instanceof QuoteRevisionLineItem) {
                continue;
            }

            $line->fill([
                'extended_price_cents' => $result->extendedPriceCents,
                'line_discount_amount_cents' => $result->lineDiscountAmountCents,
                'net_line_total_cents' => $result->netLineTotalCents,
            ]);

            if ($line->isDirty()) {
                $line->save();
            }
        }

        foreach ($totals->adjustments as $result) {
            $adjustment = $adjustments->first(
                fn (QuoteRevisionAdjustment $candidate): bool => (string) $candidate->id === $result->key
            );

            if (! $adjustment instanceof QuoteRevisionAdjustment) {
                continue;
            }

            $adjustment->fill(['amount_cents' => $result->amountCents]);

            if ($adjustment->isDirty()) {
                $adjustment->save();
            }
        }

        $approvalRequired = $totals->meetsApprovalThreshold
            || $lines->contains(fn (QuoteRevisionLineItem $line): bool => $line->approval_required)
            || $adjustments->contains(fn (QuoteRevisionAdjustment $adjustment): bool => $adjustment->approval_required);

        $revision->forceFill([
            'subtotal_cents' => $totals->netLineSubtotalCents,
            'discount_cents' => $totals->lineDiscountTotalCents + $totals->quoteDiscountTotalCents,
            'taxable_amount_cents' => $totals->provisionalTaxableBasisCents,
            'tax_cents' => 0,
            // Grand total is provisional pre-tax until the 2D tax engine resolves the revision.
            'grand_total_cents' => $totals->finalPretaxAmountCents,
            'tax_calculation_status' => QuoteTaxCalculationStatus::Pending,
            'tax_snapshot_json' => null,
            'tax_calculated_at' => null,
            'approval_required' => $approvalRequired,
            'approval_reason_snapshot' => $this->approvalReasons->build($lines, $adjustments, $totals),
        ])->save();

        return $totals;
    }

    /**
     * @return Collection<int, QuoteRevisionLineItem>
     */
    private function lines(QuoteRevision $revision): Collection
    {
        return QuoteRevisionLineItem::query()
            ->where('quote_revision_id', $revision->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, QuoteRevisionAdjustment>
     */
    private function adjustments(QuoteRevision $revision): Collection
    {
        return QuoteRevisionAdjustment::query()
            ->where('quote_revision_id', $revision->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    private function toLineInput(QuoteRevisionLineItem $line): QuoteLineCalculationInput
    {
        return new QuoteLineCalculationInput(
            key: (string) $line->id,
            lineType: $line->line_type,
            nameSnapshot: $line->name_snapshot,
            customerDescriptionSnapshot: $line->customer_description_snapshot,
            internalDescriptionSnapshot: $line->internal_description_snapshot,
            productId: $line->product_id,
            organizationProductId: $line->organization_product_id,
            skuSnapshot: $line->sku_snapshot,
            itemKindSnapshot: $line->item_kind_snapshot,
            quantityScaled: $line->quantity_scaled,
            uomSnapshot: $line->uom_snapshot,
            calculatedUnitPriceCents: $line->calculated_unit_price_cents,
            finalUnitPriceCents: $line->final_unit_price_cents,
            lineDiscountMethod: $line->line_discount_method,
            lineDiscountValue: $line->line_discount_value,
            isTaxable: $line->is_taxable,
            priceOverride: $line->price_override,
            overrideReason: $line->override_reason,
            belowMinimum: $line->below_minimum,
            approvalRequired: $line->approval_required,
            approvalReasons: $this->reasonList($line->approval_reason_json),
            materialCostMicroUnits: $line->material_cost_micro_units,
            laborCostMicroUnits: $line->labor_cost_micro_units,
            overheadCostMicroUnits: $line->overhead_cost_micro_units,
            totalCostMicroUnits: $line->total_cost_micro_units,
            pricingMethodSnapshot: $line->pricing_method_snapshot,
            markupBasisPointsSnapshot: $line->markup_basis_points_snapshot,
            marginBasisPointsSnapshot: $line->margin_basis_points_snapshot,
            pricingVersionSnapshot: $line->pricing_version_snapshot,
            componentsVersionSnapshot: $line->components_version_snapshot,
            componentCostBreakdown: $line->component_cost_breakdown_json,
            pricingInputSnapshot: $line->pricing_input_snapshot_json,
            pricingResultSnapshot: $line->pricing_result_snapshot_json,
        );
    }

    private function toAdjustmentInput(QuoteRevisionAdjustment $adjustment): QuoteAdjustmentCalculationInput
    {
        return new QuoteAdjustmentCalculationInput(
            key: (string) $adjustment->id,
            adjustmentType: $adjustment->adjustment_type,
            descriptionSnapshot: $adjustment->description_snapshot,
            method: $adjustment->method,
            inputValue: $adjustment->input_value,
            isTaxable: $adjustment->is_taxable,
            approvalRequired: $adjustment->approval_required,
            approvalReasons: $this->reasonList($adjustment->approval_reason_json),
        );
    }

    /**
     * @param  array<string, mixed>|null  $approvalReasonJson
     * @return list<string>|null
     */
    private function reasonList(?array $approvalReasonJson): ?array
    {
        $reasons = $approvalReasonJson['reasons'] ?? null;

        if (! is_array($reasons)) {
            return null;
        }

        return array_values(array_filter($reasons, static fn (mixed $reason): bool => is_string($reason)));
    }
}
