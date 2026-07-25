<?php

namespace App\Support\Catalog;

use App\Enums\InventoryTrackingMode;
use App\Enums\ItemKind;

/**
 * Classification-aware OrganizationProduct defaults by Product Master item kind.
 * Callers may override these values; existing records are never retroactively changed.
 */
final class ItemClassificationDefaults
{
    /**
     * @return array{
     *     is_sellable: bool,
     *     is_purchasable: bool,
     *     inventory_tracking_mode: string
     * }
     */
    public static function for(ItemKind $itemKind): array
    {
        return match ($itemKind) {
            ItemKind::Product => [
                'is_sellable' => true,
                'is_purchasable' => false,
                'inventory_tracking_mode' => InventoryTrackingMode::None->value,
            ],
            ItemKind::Material => [
                'is_sellable' => false,
                'is_purchasable' => true,
                'inventory_tracking_mode' => InventoryTrackingMode::PeriodicExternal->value,
            ],
            ItemKind::Service => [
                'is_sellable' => true,
                'is_purchasable' => false,
                'inventory_tracking_mode' => InventoryTrackingMode::None->value,
            ],
        };
    }
}
