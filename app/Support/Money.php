<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public const COST_SCALE = 4;

    public const MARKUP_SCALE = 2;

    /** Micro-units per one US dollar. */
    public const MICRO_UNITS_PER_DOLLAR = 10_000;

    /** Micro-units per one US cent. */
    public const MICRO_UNITS_PER_CENT = 100;

    /** Basis points representing 100%. */
    public const BASIS_POINTS_PER_UNIT = 10_000;

    /** Denominator for parts-per-million rates, so 8% is 80,000 ppm. */
    public const RATE_PARTS_PER_MILLION = 1_000_000;

    /**
     * Convert a decimal dollar amount to integer cents (half-up rounding).
     */
    public static function dollarsToCents(string $dollars): int
    {
        self::assertDecimalString($dollars);

        if (bccomp($dollars, '0', self::COST_SCALE) < 0) {
            throw new InvalidArgumentException('Money amounts cannot be negative.');
        }

        return (int) self::roundScaled($dollars, 2);
    }

    /**
     * Convert integer cents to a decimal dollar string with two fractional digits.
     *
     * @return numeric-string
     */
    public static function centsToDollars(int $cents): string
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('Cents cannot be negative.');
        }

        return bcdiv((string) $cents, '100', 2);
    }

    /**
     * Convert a decimal dollar amount to fixed-point micro-units (four decimal places).
     */
    public static function dollarsToMicroUnits(string $dollars): int
    {
        self::assertDecimalString($dollars);

        if (bccomp($dollars, '0', self::COST_SCALE) < 0) {
            throw new InvalidArgumentException('Money amounts cannot be negative.');
        }

        return (int) self::roundScaled($dollars, self::COST_SCALE);
    }

    /**
     * Convert micro-units back to a decimal dollar string with four fractional digits.
     *
     * @return numeric-string
     */
    public static function microUnitsToDollars(int $microUnits): string
    {
        if ($microUnits < 0) {
            throw new InvalidArgumentException('Micro-units cannot be negative.');
        }

        return bcdiv((string) $microUnits, bcpow('10', (string) self::COST_SCALE), self::COST_SCALE);
    }

    /**
     * Convert a percentage string (e.g. "50" or "50.25") to basis points (hundredths of a percent).
     */
    public static function percentToBasisPoints(string $percent): int
    {
        self::assertDecimalString($percent);

        if (bccomp($percent, '0', self::MARKUP_SCALE) < 0) {
            throw new InvalidArgumentException('Markup cannot be negative.');
        }

        return (int) self::roundScaled($percent, self::MARKUP_SCALE);
    }

    /**
     * Convert basis points to a percentage string with two fractional digits.
     *
     * @return numeric-string
     */
    public static function basisPointsToPercent(int $basisPoints): string
    {
        if ($basisPoints < 0) {
            throw new InvalidArgumentException('Basis points cannot be negative.');
        }

        return bcdiv((string) $basisPoints, bcpow('10', (string) self::MARKUP_SCALE), self::MARKUP_SCALE);
    }

    /**
     * Convert a percentage string (e.g. "8" or "8.5") to parts per million, so 8% is 80,000 ppm.
     */
    public static function percentToRatePartsPerMillion(string $percent): int
    {
        self::assertDecimalString($percent);

        $scaled = bcmul($percent, (string) intdiv(self::RATE_PARTS_PER_MILLION, 100), 8);

        return self::intFromNumericString(
            self::roundScaled($scaled, 0),
            'Parts-per-million rate overflows integer range.',
        );
    }

    /**
     * Convert a parts-per-million rate back to a percentage string with four fractional digits.
     *
     * @return numeric-string
     */
    public static function ratePartsPerMillionToPercent(int $ratePpm): string
    {
        if ($ratePpm < 0) {
            throw new InvalidArgumentException('Parts-per-million rate cannot be negative.');
        }

        return bcdiv((string) $ratePpm, (string) intdiv(self::RATE_PARTS_PER_MILLION, 100), 4);
    }

    /**
     * Calculate suggested list price in cents from cost micro-units and markup basis points.
     */
    public static function suggestedListPriceCents(int $costMicroUnits, int $markupBasisPoints): int
    {
        if ($costMicroUnits < 0 || $markupBasisPoints < 0) {
            throw new InvalidArgumentException('Cost and markup cannot be negative.');
        }

        return self::sellCentsFromMarkup($costMicroUnits, $markupBasisPoints);
    }

    /**
     * Add two non-negative micro-unit amounts with overflow detection.
     */
    public static function addMicroUnits(int $left, int $right): int
    {
        if ($left < 0 || $right < 0) {
            throw new InvalidArgumentException('Micro-units cannot be negative.');
        }

        $sum = bcadd((string) $left, (string) $right, 0);

        return self::intFromNumericString($sum, 'Micro-unit sum overflows integer range.');
    }

    /**
     * Apply a basis-point rate to a micro-unit base and round half-up to micro-units.
     *
     * overhead = round_half_up(base × rate_basis_points / 10,000)
     */
    public static function applyBasisPointsToMicroUnits(int $baseMicroUnits, int $rateBasisPoints): int
    {
        if ($baseMicroUnits < 0 || $rateBasisPoints < 0) {
            throw new InvalidArgumentException('Base and rate cannot be negative.');
        }

        $product = bcmul((string) $baseMicroUnits, (string) $rateBasisPoints, 0);
        $raw = bcdiv($product, (string) self::BASIS_POINTS_PER_UNIT, self::COST_SCALE + 2);

        return self::intFromNumericString(
            self::roundScaled($raw, 0),
            'Basis-point micro-unit result overflows integer range.',
        );
    }

    /**
     * Apply a parts-per-million rate to a cent amount and round half-up to cents.
     *
     * result = round_half_up(cents × rate_ppm / 1,000,000)
     */
    public static function applyRatePartsPerMillionToCents(int $cents, int $ratePpm): int
    {
        if ($cents < 0 || $ratePpm < 0) {
            throw new InvalidArgumentException('Cents and parts-per-million rate cannot be negative.');
        }

        $product = bcmul((string) $cents, (string) $ratePpm, 0);
        $raw = bcdiv($product, (string) self::RATE_PARTS_PER_MILLION, 8);

        return self::intFromNumericString(
            self::roundScaled($raw, 0),
            'Tax amount overflows integer range.',
        );
    }

    /**
     * Proportional share of an amount, rounded half-up.
     *
     * share = round_half_up(amount × numerator / denominator)
     */
    public static function proportionalShareOfCents(int $amountCents, int $numeratorCents, int $denominatorCents): int
    {
        if ($amountCents < 0 || $numeratorCents < 0 || $denominatorCents < 0) {
            throw new InvalidArgumentException('Proportional share inputs cannot be negative.');
        }

        if ($denominatorCents === 0) {
            throw new InvalidArgumentException('Proportional share denominator cannot be zero.');
        }

        $product = bcmul((string) $amountCents, (string) $numeratorCents, 0);
        $raw = bcdiv($product, (string) $denominatorCents, 8);

        return self::intFromNumericString(
            self::roundScaled($raw, 0),
            'Proportional share overflows integer range.',
        );
    }

    /**
     * Selling price in cents from total cost micro-units and markup basis points.
     *
     * sell = total_cost × (1 + markup_basis_points / 10,000), then half-up to cents.
     */
    public static function sellCentsFromMarkup(int $totalCostMicroUnits, int $markupBasisPoints): int
    {
        if ($totalCostMicroUnits < 0 || $markupBasisPoints < 0) {
            throw new InvalidArgumentException('Cost and markup cannot be negative.');
        }

        $factor = bcadd(
            '1',
            bcdiv((string) $markupBasisPoints, (string) self::BASIS_POINTS_PER_UNIT, 12),
            12,
        );
        $sellMicroUnits = bcmul((string) $totalCostMicroUnits, $factor, 12);

        return self::microUnitsDecimalToCents($sellMicroUnits);
    }

    /**
     * Selling price in cents from total cost micro-units and target margin basis points.
     *
     * sell = total_cost / (1 - target_margin_basis_points / 10,000), then half-up to cents.
     * Target margin must be strictly below 100% (10,000 bp).
     */
    public static function sellCentsFromTargetMargin(int $totalCostMicroUnits, int $targetMarginBasisPoints): int
    {
        if ($totalCostMicroUnits < 0 || $targetMarginBasisPoints < 0) {
            throw new InvalidArgumentException('Cost and target margin cannot be negative.');
        }

        if ($targetMarginBasisPoints >= self::BASIS_POINTS_PER_UNIT) {
            throw new InvalidArgumentException('Target margin must be below 100%.');
        }

        $denominator = bcsub(
            '1',
            bcdiv((string) $targetMarginBasisPoints, (string) self::BASIS_POINTS_PER_UNIT, 12),
            12,
        );
        $sellMicroUnits = bcdiv((string) $totalCostMicroUnits, $denominator, 12);

        return self::microUnitsDecimalToCents($sellMicroUnits);
    }

    /**
     * Round a micro-unit amount (possibly fractional as a decimal string) half-up to cents.
     *
     * 100 micro-units = 1 cent.
     */
    public static function microUnitsDecimalToCents(string $microUnits): int
    {
        self::assertDecimalString($microUnits);

        if (bccomp($microUnits, '0', 12) < 0) {
            throw new InvalidArgumentException('Micro-units cannot be negative.');
        }

        $cents = bcdiv($microUnits, (string) self::MICRO_UNITS_PER_CENT, 12);

        return self::intFromNumericString(
            self::roundScaled($cents, 0),
            'Cent conversion overflows integer range.',
        );
    }

    /**
     * Convert integer micro-units to cents with half-up rounding.
     */
    public static function microUnitsToCents(int $microUnits): int
    {
        if ($microUnits < 0) {
            throw new InvalidArgumentException('Micro-units cannot be negative.');
        }

        return self::microUnitsDecimalToCents((string) $microUnits);
    }

    /**
     * Multiply integer cents by a validated positive decimal quantity and round half-up to cents.
     */
    public static function multiplyCentsByQuantity(int $cents, string $quantity, int $quantityScale): int
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('Cents cannot be negative.');
        }

        self::assertQuantityString($quantity, $quantityScale);

        $extended = bcmul((string) $cents, $quantity, $quantityScale + 2);

        return self::intFromNumericString(
            self::roundScaled($extended, 0),
            'Extended price overflows integer range.',
        );
    }

    /**
     * Normalize a validated quantity to a decimal string without trailing zeros beyond scale.
     */
    public static function normalizeQuantity(string $quantity, int $quantityScale): string
    {
        self::assertQuantityString($quantity, $quantityScale);

        $normalized = bcadd($quantity, '0', $quantityScale);

        if (str_contains($normalized, '.')) {
            $normalized = rtrim(rtrim($normalized, '0'), '.');
        }

        if ($normalized === '') {
            return '0';
        }

        self::assertDecimalString($normalized);

        return $normalized;
    }

    /**
     * @phpstan-assert numeric-string $quantity
     */
    public static function assertQuantityString(string $quantity, int $quantityScale): void
    {
        if ($quantityScale < 0) {
            throw new InvalidArgumentException('Quantity scale cannot be negative.');
        }

        if (str_contains(strtolower($quantity), 'e')) {
            throw new InvalidArgumentException('Quantity may not use exponent notation.');
        }

        if (str_starts_with($quantity, '-') || ! preg_match('/^\d+(\.\d+)?$/', $quantity)) {
            throw new InvalidArgumentException('Invalid quantity decimal string.');
        }

        self::assertDecimalString($quantity);

        if (str_contains($quantity, '.')) {
            $fraction = substr($quantity, strpos($quantity, '.') + 1);
            if (strlen($fraction) > $quantityScale) {
                throw new InvalidArgumentException(
                    "Quantity may have at most {$quantityScale} decimal places."
                );
            }
        }

        if (bccomp($quantity, '0', $quantityScale) <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }
    }

    /**
     * @param  numeric-string  $amount
     * @return numeric-string
     */
    private static function roundScaled(string $amount, int $scale): string
    {
        $factor = bcpow('10', (string) $scale);
        $shifted = bcmul($amount, $factor, $scale + 2);

        if (bccomp($amount, '0', $scale + 2) >= 0) {
            $shifted = bcadd($shifted, '0.5', 0);
        } else {
            $shifted = bcsub($shifted, '0.5', 0);
        }

        return bcdiv($shifted, '1', 0);
    }

    /**
     * @param  numeric-string  $value
     */
    private static function intFromNumericString(string $value, string $overflowMessage): int
    {
        if (bccomp($value, (string) PHP_INT_MAX, 0) > 0 || bccomp($value, (string) PHP_INT_MIN, 0) < 0) {
            throw new InvalidArgumentException($overflowMessage);
        }

        return (int) $value;
    }

    /**
     * @phpstan-assert numeric-string $value
     */
    private static function assertDecimalString(string $value): void
    {
        if (! preg_match('/^\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException('Invalid decimal money string.');
        }
    }
}
