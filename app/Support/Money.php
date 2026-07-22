<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public const COST_SCALE = 4;

    public const MARKUP_SCALE = 2;

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
     * Calculate suggested list price in cents from cost micro-units and markup basis points.
     */
    public static function suggestedListPriceCents(int $costMicroUnits, int $markupBasisPoints): int
    {
        if ($costMicroUnits < 0 || $markupBasisPoints < 0) {
            throw new InvalidArgumentException('Cost and markup cannot be negative.');
        }

        $costDollars = self::microUnitsToDollars($costMicroUnits);
        $markupMultiplier = self::decimalString(bcadd(
            '1',
            bcdiv((string) $markupBasisPoints, '10000', 6),
            6,
        ));
        $sellDollars = self::decimalString(bcmul($costDollars, $markupMultiplier, self::COST_SCALE + 2));

        return self::dollarsToCents($sellDollars);
    }

    /**
     * @return numeric-string
     */
    private static function decimalString(string $value): string
    {
        self::assertDecimalString($value);

        return $value;
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
     * @phpstan-assert numeric-string $value
     */
    private static function assertDecimalString(string $value): void
    {
        if (! preg_match('/^\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException('Invalid decimal money string.');
        }
    }
}
