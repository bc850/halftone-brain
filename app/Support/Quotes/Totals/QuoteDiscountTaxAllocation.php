<?php

namespace App\Support\Quotes\Totals;

/**
 * How a quote-level discount split across taxable and nontaxable line nets, and
 * the taxable basis that split produces.
 */
final readonly class QuoteDiscountTaxAllocation
{
    public function __construct(
        public int $taxableLineNetCents,
        public int $nontaxableLineNetCents,
        public int $eligibleLineNetCents,
        public int $quoteDiscountCents,
        public int $taxableDiscountAllocationCents,
        public int $nontaxableDiscountAllocationCents,
        public int $taxablePositiveAdjustmentCents,
        public int $taxableBasisCents,
    ) {}
}
