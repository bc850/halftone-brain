<?php

namespace App\Support\Catalog\ComponentCost;

/**
 * Immutable rollup of estimated material cost for a finished organization product.
 *
 * The estimate does not consume inventory, track quantity on hand, or imply
 * that stock was deducted.
 */
final readonly class ComponentCostEstimate
{
    /**
     * @param  list<ComponentCostBreakdown>  $breakdowns
     */
    public function __construct(
        public array $breakdowns,
        public int $totalEstimatedMaterialCostMicroUnits,
        public bool $isEstimateOnly = true,
        public bool $doesNotConsumeInventory = true,
    ) {}
}
