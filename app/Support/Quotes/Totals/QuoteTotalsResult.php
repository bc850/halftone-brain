<?php

namespace App\Support\Quotes\Totals;

use App\Enums\QuoteTaxCalculationStatus;

/**
 * Immutable quote totals result. Tax remains unresolved in 2B.1.
 *
 * meetsApprovalThreshold is true only when final pretax amount is
 * strictly greater than QuoteTotalsCalculator::APPROVAL_THRESHOLD_CENTS.
 */
final readonly class QuoteTotalsResult
{
    /**
     * @param  list<QuoteLineCalculationResult>  $lines
     * @param  list<QuoteAdjustmentCalculationResult>  $adjustments
     * @param  array{fee: int, shipping: int, installation: int, other: int}  $positiveAdjustmentTotalsByType
     */
    public function __construct(
        public int $grossLineSubtotalCents,
        public int $lineDiscountTotalCents,
        public int $netLineSubtotalCents,
        public int $quoteDiscountTotalCents,
        public array $positiveAdjustmentTotalsByType,
        public int $positiveAdjustmentTotalCents,
        public int $finalPretaxAmountCents,
        public int $provisionalTaxableBasisCents,
        public int $approvalThresholdBasisCents,
        public array $lines,
        public array $adjustments,
        public QuoteTaxCalculationStatus $taxStatus,
        public bool $taxUnresolved,
        public bool $meetsApprovalThreshold,
    ) {}
}
