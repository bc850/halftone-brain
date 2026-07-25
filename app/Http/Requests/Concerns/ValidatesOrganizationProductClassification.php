<?php

namespace App\Http\Requests\Concerns;

use App\Enums\InventoryTrackingMode;
use App\Enums\ItemKind;
use App\Enums\UnitOfMeasure;
use App\Support\Catalog\ItemClassificationDefaults;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait ValidatesOrganizationProductClassification
{
    /**
     * @return array<string, mixed>
     */
    protected function classificationFieldRules(bool $itemKindRequired = false): array
    {
        $rules = [
            'is_sellable' => ['sometimes', 'boolean'],
            'is_purchasable' => ['sometimes', 'boolean'],
            'inventory_tracking_mode' => ['sometimes', Rule::enum(InventoryTrackingMode::class)],
            'purchase_unit_of_measure' => ['nullable', Rule::enum(UnitOfMeasure::class)],
            'stock_unit_of_measure' => ['nullable', Rule::enum(UnitOfMeasure::class)],
            'usage_unit_of_measure' => ['nullable', Rule::enum(UnitOfMeasure::class)],
        ];

        if ($itemKindRequired) {
            $rules['item_kind'] = ['required', Rule::enum(ItemKind::class)];
        }

        return $rules;
    }

    /**
     * Apply kind-based defaults when classification fields are omitted.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function applyClassificationDefaults(array $data, ItemKind $itemKind): array
    {
        $defaults = ItemClassificationDefaults::for($itemKind);

        if (! array_key_exists('is_sellable', $data)) {
            $data['is_sellable'] = $defaults['is_sellable'];
        } else {
            $data['is_sellable'] = (bool) $data['is_sellable'];
        }

        if (! array_key_exists('is_purchasable', $data)) {
            $data['is_purchasable'] = $defaults['is_purchasable'];
        } else {
            $data['is_purchasable'] = (bool) $data['is_purchasable'];
        }

        if (! array_key_exists('inventory_tracking_mode', $data) || $data['inventory_tracking_mode'] === null || $data['inventory_tracking_mode'] === '') {
            $data['inventory_tracking_mode'] = $defaults['inventory_tracking_mode'];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function assertClassificationConsistency(array $data, ?ItemKind $itemKind = null): array
    {
        $isPurchasable = (bool) ($data['is_purchasable'] ?? false);
        $mode = InventoryTrackingMode::from((string) ($data['inventory_tracking_mode'] ?? InventoryTrackingMode::None->value));

        if (! $isPurchasable && $mode !== InventoryTrackingMode::None) {
            throw ValidationException::withMessages([
                'inventory_tracking_mode' => 'Inventory tracking must be none when the item is not purchasable.',
            ]);
        }

        if ($itemKind === ItemKind::Service && $mode !== InventoryTrackingMode::None) {
            throw ValidationException::withMessages([
                'inventory_tracking_mode' => 'Services must use inventory tracking mode none.',
            ]);
        }

        $data['inventory_tracking_mode'] = $mode->value;
        $data['is_purchasable'] = $isPurchasable;
        $data['is_sellable'] = (bool) ($data['is_sellable'] ?? false);

        foreach (['purchase_unit_of_measure', 'stock_unit_of_measure', 'usage_unit_of_measure'] as $field) {
            if (! array_key_exists($field, $data) || $data[$field] === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }
}
