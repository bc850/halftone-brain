<?php

namespace App\Support\Catalog\ComponentCost;

use App\Enums\UnitOfMeasure;

/**
 * Immutable conversion fact for a material organization product.
 */
final readonly class ComponentConversionInput
{
    public function __construct(
        public UnitOfMeasure $fromUnit,
        public UnitOfMeasure $toUnit,
        public int $numerator,
        public int $denominator,
        public bool $isActive,
    ) {
        if ($this->numerator < 1 || $this->denominator < 1) {
            throw new InvalidComponentCostException('Conversion ratio must be greater than zero.');
        }
    }
}
