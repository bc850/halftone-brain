<?php

namespace App\Support\Catalog\ComponentCost;

use App\Enums\ItemKind;

/**
 * Immutable finished-item facts plus ordered component lines.
 */
final readonly class ComponentCostEstimateInput
{
    /**
     * @param  list<ComponentLineInput>  $components
     */
    public function __construct(
        public int $organizationProductId,
        public int $parentAccountId,
        public int $organizationId,
        public ItemKind $itemKind,
        public bool $isSellable,
        public array $components,
    ) {}
}
