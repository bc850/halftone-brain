<?php

namespace App\Support\Quotes\Snapshots;

use App\Support\Quotes\Totals\QuoteLineCalculationInput;
use App\Support\Quotes\Totals\QuoteLineCalculationResult;
use App\Support\Quotes\Totals\QuoteTotalsResult;

/**
 * Customer-safe projection that omits internal cost and approval metadata.
 *
 * @phpstan-type CustomerSafeLine array{
 *     key: string,
 *     line_type: string,
 *     name: string,
 *     customer_description: string|null,
 *     sku: string|null,
 *     quantity_scaled: int,
 *     uom: string|null,
 *     unit_price_cents: int|null,
 *     extended_price_cents: int,
 *     line_discount_amount_cents: int,
 *     net_line_total_cents: int,
 *     is_taxable: bool
 * }
 * @phpstan-type CustomerSafeTotals array{
 *     gross_line_subtotal_cents: int,
 *     line_discount_total_cents: int,
 *     net_line_subtotal_cents: int,
 *     quote_discount_total_cents: int,
 *     positive_adjustment_total_cents: int,
 *     final_pretax_amount_cents: int,
 *     tax_status: string,
 *     tax_unresolved: true,
 *     customer_grand_total_final: false,
 *     lines: list<CustomerSafeLine>
 * }
 */
final class CustomerSafeQuoteProjection
{
    /**
     * @param  list<QuoteLineCalculationInput>  $lineInputs
     * @return CustomerSafeTotals
     */
    public function fromTotals(QuoteTotalsResult $totals, array $lineInputs = []): array
    {
        $inputsByKey = [];
        foreach ($lineInputs as $input) {
            $inputsByKey[$input->key] = $input;
        }

        $lines = [];
        foreach ($totals->lines as $line) {
            $input = $inputsByKey[$line->key] ?? null;
            $lines[] = $this->projectLine($line, $input);
        }

        return [
            'gross_line_subtotal_cents' => $totals->grossLineSubtotalCents,
            'line_discount_total_cents' => $totals->lineDiscountTotalCents,
            'net_line_subtotal_cents' => $totals->netLineSubtotalCents,
            'quote_discount_total_cents' => $totals->quoteDiscountTotalCents,
            'positive_adjustment_total_cents' => $totals->positiveAdjustmentTotalCents,
            'final_pretax_amount_cents' => $totals->finalPretaxAmountCents,
            'tax_status' => $totals->taxStatus->value,
            'tax_unresolved' => true,
            'customer_grand_total_final' => false,
            'lines' => $lines,
        ];
    }

    /**
     * @return CustomerSafeLine
     */
    private function projectLine(QuoteLineCalculationResult $line, ?QuoteLineCalculationInput $input): array
    {
        return [
            'key' => $line->key,
            'line_type' => $line->lineType->value,
            'name' => $input === null ? '' : $input->nameSnapshot,
            'customer_description' => $input?->customerDescriptionSnapshot,
            'sku' => $input?->skuSnapshot,
            'quantity_scaled' => $line->quantityScaled,
            'uom' => $input?->uomSnapshot,
            'unit_price_cents' => $line->unitPriceCents,
            'extended_price_cents' => $line->extendedPriceCents,
            'line_discount_amount_cents' => $line->lineDiscountAmountCents,
            'net_line_total_cents' => $line->netLineTotalCents,
            'is_taxable' => $line->isTaxable,
        ];
    }

    /**
     * Keys that must never appear in a customer-safe payload.
     *
     * @return list<string>
     */
    public static function forbiddenKeys(): array
    {
        return array_values(array_unique(array_merge(self::quoteForbiddenKeys(), CustomerSafeTaxProjection::forbiddenKeys())));
    }

    /**
     * @return list<string>
     */
    private static function quoteForbiddenKeys(): array
    {
        return [
            'material_cost_micro_units',
            'labor_cost_micro_units',
            'overhead_cost_micro_units',
            'total_cost_micro_units',
            'markup_basis_points',
            'margin_basis_points',
            'markup_basis_points_snapshot',
            'margin_basis_points_snapshot',
            'approval_reason',
            'approval_reasons',
            'approval_required',
            'approval_reason_json',
            'approval_reason_snapshot',
            'internal_description',
            'internal_description_snapshot',
            'internal_notes',
            'pricing_version',
            'pricing_version_snapshot',
            'components_version',
            'components_version_snapshot',
            'component_cost_breakdown',
            'component_cost_breakdown_json',
            'pricing_input_snapshot_json',
            'pricing_result_snapshot_json',
            'override_reason',
            'price_override',
            'below_minimum',
            'certificate_number',
            'employee_recorded_reason',
        ];
    }
}
