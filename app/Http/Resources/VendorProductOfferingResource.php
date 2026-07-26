<?php

namespace App\Http\Resources;

use App\Models\VendorProductOffering;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;

final class VendorProductOfferingResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(VendorProductOffering $offering): array
    {
        $offering->loadMissing(['product:id,name,sku', 'vendor:id,name']);

        return [
            'id' => $offering->id,
            'product_id' => $offering->product_id,
            'vendor_id' => $offering->vendor_id,
            'vendor_sku' => $offering->vendor_sku,
            'vendor_description' => $offering->vendor_description,
            'manufacturer' => $offering->manufacturer,
            'manufacturer_part_number' => $offering->manufacturer_part_number,
            'product_url' => $offering->product_url,
            'purchase_uom' => $offering->purchase_uom->value,
            'purchase_uom_label' => $offering->purchase_uom->label(),
            'package_quantity' => ComponentCostEstimator::scaledToQuantity($offering->package_quantity_scaled),
            'package_quantity_scaled' => $offering->package_quantity_scaled,
            'minimum_order_quantity' => $offering->minimum_order_quantity_scaled === null
                ? null
                : ComponentCostEstimator::scaledToQuantity($offering->minimum_order_quantity_scaled),
            'minimum_order_quantity_scaled' => $offering->minimum_order_quantity_scaled,
            'lead_time_days' => $offering->lead_time_days,
            'status' => $offering->status->value,
            'status_label' => $offering->status->label(),
            'discontinued_at' => $offering->discontinued_at?->toIso8601String(),
            'product' => $offering->product === null ? null : [
                'id' => $offering->product->id,
                'name' => $offering->product->name,
                'sku' => $offering->product->sku,
            ],
            'vendor' => $offering->vendor === null ? null : [
                'id' => $offering->vendor->id,
                'name' => $offering->vendor->name,
            ],
        ];
    }

    /**
     * @param  iterable<int, VendorProductOffering>  $offerings
     * @return list<array<string, mixed>>
     */
    public static function collection(iterable $offerings): array
    {
        $payload = [];

        foreach ($offerings as $offering) {
            $payload[] = self::make($offering);
        }

        return $payload;
    }
}
