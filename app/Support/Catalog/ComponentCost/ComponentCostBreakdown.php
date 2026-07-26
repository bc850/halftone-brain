<?php

namespace App\Support\Catalog\ComponentCost;

use App\Enums\UnitOfMeasure;

/**
 * Immutable per-component estimate breakdown.
 *
 * Quantities use the six-decimal scaled integer convention
 * ({@see ComponentCostEstimator::QUANTITY_SCALE}).
 */
final readonly class ComponentCostBreakdown
{
    public function __construct(
        public int $componentOrganizationProductId,
        public int $baseUsageQuantityScaled,
        public int $wasteBasisPoints,
        public int $wasteAdjustedQuantityScaled,
        public UnitOfMeasure $usageUnitOfMeasure,
        public string $convertedPurchaseQuantity,
        public UnitOfMeasure $purchaseUnitOfMeasure,
        public int $purchaseCostMicroUnits,
        public int $estimatedComponentCostMicroUnits,
        public ComponentConversionDirection $conversionDirection,
    ) {}
}
