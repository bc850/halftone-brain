<?php

namespace App\Support\Catalog\ComponentCost;

use App\Enums\ItemKind;
use App\Enums\UnitOfMeasure;

/**
 * Immutable raw-material facts for one estimated component line.
 */
final readonly class ComponentLineInput
{
    /**
     * @param  list<ComponentConversionInput>  $conversions
     */
    public function __construct(
        public int $componentOrganizationProductId,
        public int $parentAccountId,
        public int $organizationId,
        public ItemKind $itemKind,
        public bool $isPurchasable,
        public ?UnitOfMeasure $purchaseUnitOfMeasure,
        public ?int $purchaseCostMicroUnits,
        public int $quantityScaled,
        public UnitOfMeasure $usageUnitOfMeasure,
        public int $wasteBasisPoints,
        public array $conversions = [],
    ) {}
}
