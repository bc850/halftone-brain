<?php

namespace App\Support\Catalog;

use App\Enums\UnitOfMeasure;
use InvalidArgumentException;

/**
 * Pure exact unit conversion using integer ratios and BCMath.
 * Performs no database writes and does not walk conversion graphs.
 */
final class UnitConversion
{
    public function __construct(
        public readonly UnitOfMeasure $fromUnit,
        public readonly UnitOfMeasure $toUnit,
        public readonly int $numerator,
        public readonly int $denominator,
    ) {
        if ($this->numerator < 1) {
            throw new InvalidArgumentException('Conversion numerator must be greater than zero.');
        }

        if ($this->denominator < 1) {
            throw new InvalidArgumentException('Conversion denominator must be greater than zero.');
        }
    }

    /**
     * Convert a decimal-string quantity from from_unit to to_unit.
     *
     * Formula: to_quantity = from_quantity × numerator / denominator
     *
     * @return numeric-string
     */
    public function convert(string $fromQuantity, int $scale = 8): string
    {
        $this->assertQuantityString($fromQuantity);

        $scaled = bcmul($fromQuantity, (string) $this->numerator, $scale);

        return bcdiv($scaled, (string) $this->denominator, $scale);
    }

    /**
     * @phpstan-assert numeric-string $quantity
     */
    private function assertQuantityString(string $quantity): void
    {
        if (str_contains(strtolower($quantity), 'e')) {
            throw new InvalidArgumentException('Quantity may not use exponent notation.');
        }

        if (str_starts_with($quantity, '-') || ! preg_match('/^\d+(\.\d+)?$/', $quantity)) {
            throw new InvalidArgumentException('Quantity must be a decimal string.');
        }
    }
}
