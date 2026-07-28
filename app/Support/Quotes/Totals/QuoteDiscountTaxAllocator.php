<?php

namespace App\Support\Quotes\Totals;

use App\Support\Money;

/**
 * Splits a quote-level discount between taxable and nontaxable line nets and
 * derives the taxable basis.
 *
 * A quote discount applies to the quote as a whole, so it has to be shared
 * between taxable and nontaxable lines in proportion to what each contributed;
 * otherwise a discount on nontaxable work would erode taxable revenue.
 *
 * Rounding order, which is what keeps the result reproducible:
 *
 * 1. Line nets arrive already rounded half-up by QuoteTotalsCalculator.
 * 2. The quote discount total arrives already computed by the same calculator.
 * 3. The taxable share is round_half_up(discount × taxable_net / eligible_net).
 * 4. The nontaxable share is the exact remainder, so the two shares always sum
 *    back to the quote discount and no cent is created or lost.
 * 5. The taxable basis clamps at zero before taxable positive charges are added,
 *    so a discount can never push the basis negative and then be offset by fees.
 * 6. Tax itself is round_half_up(basis × rate_ppm / 1,000,000), applied by
 *    QuoteTaxCalculator against the basis produced here.
 *
 * Pure: no HTTP, auth, TenantContext, Eloquent, database access, or events.
 */
final class QuoteDiscountTaxAllocator
{
    public function allocate(
        int $taxableLineNetCents,
        int $nontaxableLineNetCents,
        int $quoteDiscountCents,
        int $taxablePositiveAdjustmentCents = 0,
    ): QuoteDiscountTaxAllocation {
        $this->assertNonNegative($taxableLineNetCents, 'Taxable line net');
        $this->assertNonNegative($nontaxableLineNetCents, 'Nontaxable line net');
        $this->assertNonNegative($quoteDiscountCents, 'Quote discount');
        $this->assertNonNegative($taxablePositiveAdjustmentCents, 'Taxable positive adjustment total');

        $eligible = $taxableLineNetCents + $nontaxableLineNetCents;

        if ($quoteDiscountCents > $eligible) {
            throw new InvalidQuoteTotalsException('Quote discount cannot exceed eligible line net total.');
        }

        if ($eligible === 0) {
            $taxableAllocation = 0;
            $nontaxableAllocation = 0;
        } else {
            $taxableAllocation = Money::proportionalShareOfCents(
                $quoteDiscountCents,
                $taxableLineNetCents,
                $eligible,
            );
            $nontaxableAllocation = $quoteDiscountCents - $taxableAllocation;
        }

        $taxableBasis = max(0, $taxableLineNetCents - $taxableAllocation) + $taxablePositiveAdjustmentCents;

        return new QuoteDiscountTaxAllocation(
            taxableLineNetCents: $taxableLineNetCents,
            nontaxableLineNetCents: $nontaxableLineNetCents,
            eligibleLineNetCents: $eligible,
            quoteDiscountCents: $quoteDiscountCents,
            taxableDiscountAllocationCents: $taxableAllocation,
            nontaxableDiscountAllocationCents: $nontaxableAllocation,
            taxablePositiveAdjustmentCents: $taxablePositiveAdjustmentCents,
            taxableBasisCents: $taxableBasis,
        );
    }

    /**
     * Same allocation, reading the line and adjustment breakdown already present
     * on a totals result.
     */
    public function allocateFromTotals(QuoteTotalsResult $totals): QuoteDiscountTaxAllocation
    {
        $taxableLineNet = 0;
        $nontaxableLineNet = 0;

        foreach ($totals->lines as $line) {
            if (! $line->isFinancial) {
                continue;
            }

            if ($line->isTaxable) {
                $taxableLineNet += $line->netLineTotalCents;

                continue;
            }

            $nontaxableLineNet += $line->netLineTotalCents;
        }

        $taxablePositiveAdjustments = 0;
        foreach ($totals->adjustments as $adjustment) {
            if ($adjustment->isPositiveCharge && $adjustment->isTaxable) {
                $taxablePositiveAdjustments += $adjustment->amountCents;
            }
        }

        return $this->allocate(
            taxableLineNetCents: $taxableLineNet,
            nontaxableLineNetCents: $nontaxableLineNet,
            quoteDiscountCents: $totals->quoteDiscountTotalCents,
            taxablePositiveAdjustmentCents: $taxablePositiveAdjustments,
        );
    }

    private function assertNonNegative(int $value, string $label): void
    {
        if ($value < 0) {
            throw new InvalidQuoteTotalsException("{$label} cannot be negative.");
        }
    }
}
