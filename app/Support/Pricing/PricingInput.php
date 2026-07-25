<?php

namespace App\Support\Pricing;

use App\Enums\OverheadMode;
use App\Enums\PricingMethod;

/**
 * Immutable pricing facts for a single unit calculation.
 *
 * Quantity is a validated positive decimal string (not a float).
 * Maximum supported quantity fractional precision: {@see PricingCalculator::QUANTITY_SCALE} digits.
 */
final readonly class PricingInput
{
    public function __construct(
        public int $materialCostMicroUnits,
        public int $laborCostMicroUnits,
        public OverheadMode $overheadMode,
        public int $overheadAmountMicroUnits,
        public int $overheadRateBasisPoints,
        public PricingMethod $pricingMethod,
        public int $markupBasisPoints,
        public int $targetMarginBasisPoints,
        public ?int $fixedPriceCents,
        public ?int $minimumPriceCents,
        public bool $allowPriceOverride,
        public ?int $requestedOverridePriceCents,
        public string $quantity,
        public string $currencyCode,
        public int $pricingVersion,
    ) {}
}
