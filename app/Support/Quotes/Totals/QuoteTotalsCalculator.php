<?php

namespace App\Support\Quotes\Totals;

use App\Enums\QuoteAdjustmentMethod;
use App\Enums\QuoteAdjustmentType;
use App\Enums\QuoteLineDiscountMethod;
use App\Enums\QuoteTaxCalculationStatus;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Money;
use App\Support\Pricing\PricingCalculator;

/**
 * Pure quote totals calculator.
 *
 * No HTTP, auth, TenantContext, Eloquent, database writes, audits, or events.
 * Selling money is integer cents. Quantity uses six-decimal scaled integers.
 */
final class QuoteTotalsCalculator
{
    public const QUANTITY_SCALE = PricingCalculator::QUANTITY_SCALE;

    public const QUANTITY_SCALE_FACTOR = ComponentCostEstimator::QUANTITY_SCALE_FACTOR;

    /** Approval threshold basis: $1,500.00 pre-tax. */
    public const APPROVAL_THRESHOLD_CENTS = 150_000;

    public const MAX_BASIS_POINTS = Money::BASIS_POINTS_PER_UNIT;

    /**
     * @param  list<QuoteLineCalculationInput>  $lines
     * @param  list<QuoteAdjustmentCalculationInput>  $adjustments
     */
    public function calculate(array $lines, array $adjustments = []): QuoteTotalsResult
    {
        $lineResults = [];
        $grossLineSubtotal = 0;
        $lineDiscountTotal = 0;
        $netLineSubtotal = 0;
        $provisionalTaxableBasis = 0;

        foreach ($lines as $line) {
            $result = $this->calculateLine($line);
            $lineResults[] = $result;

            if (! $result->isFinancial) {
                continue;
            }

            $grossLineSubtotal = $this->addCents($grossLineSubtotal, $result->extendedPriceCents);
            $lineDiscountTotal = $this->addCents($lineDiscountTotal, $result->lineDiscountAmountCents);
            $netLineSubtotal = $this->addCents($netLineSubtotal, $result->netLineTotalCents);

            if ($result->isTaxable) {
                $provisionalTaxableBasis = $this->addCents($provisionalTaxableBasis, $result->netLineTotalCents);
            }
        }

        $adjustmentResults = [];
        $quoteDiscountTotal = 0;
        $positiveByType = [
            'fee' => 0,
            'shipping' => 0,
            'installation' => 0,
            'other' => 0,
        ];
        $positiveTotal = 0;
        $eligibleForQuoteDiscount = $netLineSubtotal;
        $remainingEligible = $eligibleForQuoteDiscount;

        foreach ($adjustments as $adjustment) {
            $result = $this->calculateAdjustment($adjustment, $remainingEligible);
            $adjustmentResults[] = $result;

            if ($result->isDiscount) {
                $quoteDiscountTotal = $this->addCents($quoteDiscountTotal, $result->amountCents);
                $remainingEligible = $this->subtractCents($remainingEligible, $result->amountCents);

                continue;
            }

            $typeKey = $result->adjustmentType->value;
            if (! array_key_exists($typeKey, $positiveByType)) {
                throw new InvalidQuoteTotalsException("Unsupported positive adjustment type [{$typeKey}].");
            }

            $positiveByType[$typeKey] = $this->addCents($positiveByType[$typeKey], $result->amountCents);
            $positiveTotal = $this->addCents($positiveTotal, $result->amountCents);

            if ($result->isTaxable) {
                $provisionalTaxableBasis = $this->addCents($provisionalTaxableBasis, $result->amountCents);
            }
        }

        if ($quoteDiscountTotal > $eligibleForQuoteDiscount) {
            throw new InvalidQuoteTotalsException('Quote discounts cannot exceed eligible net line subtotal.');
        }

        $afterQuoteDiscount = $this->subtractCents($netLineSubtotal, $quoteDiscountTotal);
        $finalPretax = $this->addCents($afterQuoteDiscount, $positiveTotal);

        return new QuoteTotalsResult(
            grossLineSubtotalCents: $grossLineSubtotal,
            lineDiscountTotalCents: $lineDiscountTotal,
            netLineSubtotalCents: $netLineSubtotal,
            quoteDiscountTotalCents: $quoteDiscountTotal,
            positiveAdjustmentTotalsByType: $positiveByType,
            positiveAdjustmentTotalCents: $positiveTotal,
            finalPretaxAmountCents: $finalPretax,
            provisionalTaxableBasisCents: $provisionalTaxableBasis,
            approvalThresholdBasisCents: $finalPretax,
            lines: $lineResults,
            adjustments: $adjustmentResults,
            taxStatus: QuoteTaxCalculationStatus::Pending,
            taxUnresolved: true,
            meetsApprovalThreshold: $finalPretax >= self::APPROVAL_THRESHOLD_CENTS,
        );
    }

    private function calculateLine(QuoteLineCalculationInput $line): QuoteLineCalculationResult
    {
        if (! $line->lineType->isFinancial()) {
            if ($line->quantityScaled !== 0
                || ($line->finalUnitPriceCents ?? 0) !== 0
                || ($line->calculatedUnitPriceCents ?? 0) !== 0
                || $line->lineDiscountValue !== 0) {
                throw new InvalidQuoteTotalsException(
                    "Non-financial line [{$line->key}] must not carry quantity, price, or discount values."
                );
            }

            return new QuoteLineCalculationResult(
                key: $line->key,
                lineType: $line->lineType,
                quantityScaled: 0,
                unitPriceCents: null,
                extendedPriceCents: 0,
                lineDiscountMethod: QuoteLineDiscountMethod::None,
                lineDiscountValue: 0,
                lineDiscountAmountCents: 0,
                netLineTotalCents: 0,
                isTaxable: false,
                isFinancial: false,
            );
        }

        if ($line->quantityScaled < 1) {
            throw new InvalidQuoteTotalsException("Line [{$line->key}] quantity must be positive.");
        }

        $quantity = ComponentCostEstimator::scaledToQuantity($line->quantityScaled);
        $unitPrice = $line->finalUnitPriceCents;

        if ($unitPrice === null) {
            throw new InvalidQuoteTotalsException("Financial line [{$line->key}] requires final unit price cents.");
        }

        if ($unitPrice < 0) {
            throw new InvalidQuoteTotalsException("Line [{$line->key}] unit price cannot be negative.");
        }

        try {
            $extended = Money::multiplyCentsByQuantity($unitPrice, $quantity, self::QUANTITY_SCALE);
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidQuoteTotalsException($exception->getMessage(), 0, $exception);
        }
        $discount = $this->lineDiscountAmountCents($line, $extended);
        $net = $this->subtractCents($extended, $discount);

        return new QuoteLineCalculationResult(
            key: $line->key,
            lineType: $line->lineType,
            quantityScaled: $line->quantityScaled,
            unitPriceCents: $unitPrice,
            extendedPriceCents: $extended,
            lineDiscountMethod: $line->lineDiscountMethod,
            lineDiscountValue: $line->lineDiscountValue,
            lineDiscountAmountCents: $discount,
            netLineTotalCents: $net,
            isTaxable: $line->isTaxable,
            isFinancial: true,
        );
    }

    private function lineDiscountAmountCents(QuoteLineCalculationInput $line, int $extended): int
    {
        return match ($line->lineDiscountMethod) {
            QuoteLineDiscountMethod::None => $this->assertZeroDiscountValue($line),
            QuoteLineDiscountMethod::Fixed => $this->fixedDiscountCents($line, $extended),
            QuoteLineDiscountMethod::Percentage => $this->percentageDiscountCents($line, $extended),
        };
    }

    private function assertZeroDiscountValue(QuoteLineCalculationInput $line): int
    {
        if ($line->lineDiscountValue !== 0) {
            throw new InvalidQuoteTotalsException(
                "Line [{$line->key}] with discount method none must have discount value 0."
            );
        }

        return 0;
    }

    private function fixedDiscountCents(QuoteLineCalculationInput $line, int $extended): int
    {
        if ($line->lineDiscountValue < 0) {
            throw new InvalidQuoteTotalsException("Line [{$line->key}] fixed discount cannot be negative.");
        }

        if ($line->lineDiscountValue > $extended) {
            throw new InvalidQuoteTotalsException(
                "Line [{$line->key}] fixed discount cannot exceed extended price."
            );
        }

        return $line->lineDiscountValue;
    }

    private function percentageDiscountCents(QuoteLineCalculationInput $line, int $extended): int
    {
        if ($line->lineDiscountValue < 0 || $line->lineDiscountValue > self::MAX_BASIS_POINTS) {
            throw new InvalidQuoteTotalsException(
                "Line [{$line->key}] percentage discount must be between 0 and ".self::MAX_BASIS_POINTS.' basis points.'
            );
        }

        return $this->applyBasisPointsToCents($extended, $line->lineDiscountValue);
    }

    private function calculateAdjustment(
        QuoteAdjustmentCalculationInput $adjustment,
        int $remainingEligibleSubtotalCents,
    ): QuoteAdjustmentCalculationResult {
        if ($adjustment->adjustmentType->isDiscount()) {
            return $this->calculateQuoteDiscount($adjustment, $remainingEligibleSubtotalCents);
        }

        if ($adjustment->method !== QuoteAdjustmentMethod::Fixed) {
            throw new InvalidQuoteTotalsException(
                "Positive adjustment [{$adjustment->key}] must use fixed cents in v1."
            );
        }

        if ($adjustment->inputValue < 0) {
            throw new InvalidQuoteTotalsException(
                "Positive adjustment [{$adjustment->key}] amount cannot be negative."
            );
        }

        return new QuoteAdjustmentCalculationResult(
            key: $adjustment->key,
            adjustmentType: $adjustment->adjustmentType,
            method: $adjustment->method,
            inputValue: $adjustment->inputValue,
            amountCents: $adjustment->inputValue,
            isTaxable: $adjustment->isTaxable,
            isDiscount: false,
            isPositiveCharge: true,
        );
    }

    private function calculateQuoteDiscount(
        QuoteAdjustmentCalculationInput $adjustment,
        int $remainingEligibleSubtotalCents,
    ): QuoteAdjustmentCalculationResult {
        $amount = match ($adjustment->method) {
            QuoteAdjustmentMethod::Fixed => $this->fixedQuoteDiscountCents($adjustment, $remainingEligibleSubtotalCents),
            QuoteAdjustmentMethod::Percentage => $this->percentageQuoteDiscountCents($adjustment, $remainingEligibleSubtotalCents),
        };

        return new QuoteAdjustmentCalculationResult(
            key: $adjustment->key,
            adjustmentType: QuoteAdjustmentType::QuoteDiscount,
            method: $adjustment->method,
            inputValue: $adjustment->inputValue,
            amountCents: $amount,
            isTaxable: false,
            isDiscount: true,
            isPositiveCharge: false,
        );
    }

    private function fixedQuoteDiscountCents(
        QuoteAdjustmentCalculationInput $adjustment,
        int $remainingEligibleSubtotalCents,
    ): int {
        if ($adjustment->inputValue < 0) {
            throw new InvalidQuoteTotalsException('Quote discount cannot be negative.');
        }

        if ($adjustment->inputValue > $remainingEligibleSubtotalCents) {
            throw new InvalidQuoteTotalsException(
                'Fixed quote discount cannot exceed eligible net line subtotal.'
            );
        }

        return $adjustment->inputValue;
    }

    private function percentageQuoteDiscountCents(
        QuoteAdjustmentCalculationInput $adjustment,
        int $remainingEligibleSubtotalCents,
    ): int {
        if ($adjustment->inputValue < 0 || $adjustment->inputValue > self::MAX_BASIS_POINTS) {
            throw new InvalidQuoteTotalsException(
                'Percentage quote discount must be between 0 and '.self::MAX_BASIS_POINTS.' basis points.'
            );
        }

        $amount = $this->applyBasisPointsToCents($remainingEligibleSubtotalCents, $adjustment->inputValue);

        if ($amount > $remainingEligibleSubtotalCents) {
            throw new InvalidQuoteTotalsException(
                'Percentage quote discount cannot exceed eligible net line subtotal.'
            );
        }

        return $amount;
    }

    private function applyBasisPointsToCents(int $cents, int $basisPoints): int
    {
        if ($cents < 0 || $basisPoints < 0) {
            throw new InvalidQuoteTotalsException('Basis-point discount inputs cannot be negative.');
        }

        $raw = bcmul((string) $cents, (string) $basisPoints, 8);
        $divided = bcdiv($raw, (string) self::MAX_BASIS_POINTS, 8);

        return $this->intFromNumericString(
            $this->roundHalfUp($divided, 0),
            'Discount amount overflows integer range.',
        );
    }

    private function addCents(int $left, int $right): int
    {
        $sum = bcadd((string) $left, (string) $right, 0);

        return $this->intFromNumericString($sum, 'Cent summation overflows integer range.');
    }

    private function subtractCents(int $left, int $right): int
    {
        if ($right > $left) {
            throw new InvalidQuoteTotalsException('Cent subtraction would produce a negative total.');
        }

        return $left - $right;
    }

    /**
     * @param  numeric-string  $amount
     * @return numeric-string
     */
    private function roundHalfUp(string $amount, int $scale): string
    {
        $factor = bcpow('10', (string) $scale);
        $shifted = bcmul($amount, $factor, $scale + 2);
        $shifted = bcadd($shifted, '0.5', 0);

        return bcdiv($shifted, '1', 0);
    }

    /**
     * @param  numeric-string  $value
     */
    private function intFromNumericString(string $value, string $overflowMessage): int
    {
        if (bccomp($value, (string) PHP_INT_MAX, 0) > 0 || bccomp($value, (string) PHP_INT_MIN, 0) < 0) {
            throw new InvalidQuoteTotalsException($overflowMessage);
        }

        return (int) $value;
    }
}
