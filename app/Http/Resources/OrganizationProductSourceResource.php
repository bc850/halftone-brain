<?php

namespace App\Http\Resources;

use App\Models\OrganizationProduct;
use App\Models\OrganizationProductSource;
use App\Models\OrganizationProductSourcePriceEvent;
use App\Models\OrganizationProductUnitConversion;
use App\Models\User;
use App\Support\Catalog\ComponentCost\ComponentConversionInput;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Catalog\ComponentCost\InvalidComponentCostException;
use App\Support\Catalog\VendorPackagePriceNormalizer;
use App\Support\Money;

final class OrganizationProductSourceResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(
        OrganizationProductSource $source,
        User $user,
        ?OrganizationProduct $organizationProduct = null,
    ): array {
        $source->loadMissing([
            'vendorProductOffering.vendor:id,name',
            'vendorProductOffering.product:id,name,sku',
            'organizationProduct',
        ]);

        $organizationProduct ??= $source->organizationProduct;
        $offering = $source->vendorProductOffering;
        $canViewCost = $user->can('viewCost', $source);

        $lastPriceUpdate = null;
        if ($canViewCost) {
            if ($source->relationLoaded('priceEvents')) {
                $last = $source->priceEvents->sortByDesc('recorded_at')->first();
                $lastPriceUpdate = $last?->recorded_at?->toIso8601String();
            } else {
                $raw = $source->priceEvents()->latest('recorded_at')->value('recorded_at');
                $lastPriceUpdate = $raw === null ? null : (string) $raw;
            }
        }

        $payload = [
            'id' => $source->id,
            'organization_product_id' => $source->organization_product_id,
            'vendor_product_offering_id' => $source->vendor_product_offering_id,
            'currency_code' => $source->currency_code,
            'price_version' => $source->price_version,
            'is_active' => $source->is_active,
            'is_preferred' => $organizationProduct?->preferred_source_id === $source->id,
            'offering' => $offering === null ? null : [
                'id' => $offering->id,
                'vendor_sku' => $offering->vendor_sku,
                'vendor_description' => $offering->vendor_description,
                'purchase_uom' => $offering->purchase_uom->value,
                'purchase_uom_label' => $offering->purchase_uom->label(),
                'package_quantity' => ComponentCostEstimator::scaledToQuantity($offering->package_quantity_scaled),
                'status' => $offering->status->value,
                'status_label' => $offering->status->label(),
                'vendor' => $offering->vendor === null ? null : [
                    'id' => $offering->vendor->id,
                    'name' => $offering->vendor->name,
                ],
            ],
        ];

        if ($canViewCost) {
            $payload['current_package_price'] = $source->current_package_price_micro_units === null
                ? null
                : Money::microUnitsToDollars($source->current_package_price_micro_units);
            $payload['current_package_price_micro_units'] = $source->current_package_price_micro_units;
            $payload['effective_purchase_unit_cost'] = self::effectiveCostDisplay($source, $organizationProduct);
            $payload['last_price_update_at'] = $lastPriceUpdate;
        }

        return $payload;
    }

    /**
     * @param  iterable<int, OrganizationProductSource>  $sources
     * @return list<array<string, mixed>>
     */
    public static function collection(
        iterable $sources,
        User $user,
        ?OrganizationProduct $organizationProduct = null,
    ): array {
        $payload = [];

        foreach ($sources as $source) {
            $payload[] = self::make($source, $user, $organizationProduct);
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function priceEvents(OrganizationProductSource $source, User $user): array
    {
        if (! $user->can('viewCost', $source)) {
            return [];
        }

        $payload = [];

        foreach (
            $source->priceEvents()
                ->orderByDesc('recorded_at')
                ->orderByDesc('id')
                ->get() as $event
        ) {
            /** @var OrganizationProductSourcePriceEvent $event */
            $payload[] = [
                'id' => $event->id,
                'package_price' => Money::microUnitsToDollars($event->package_price_micro_units),
                'effective_purchase_unit_cost' => Money::microUnitsToDollars($event->effective_purchase_unit_cost_micro_units),
                'currency_code' => $event->currency_code,
                'note' => $event->note,
                'recorded_at' => $event->recorded_at->toIso8601String(),
                'actor_user_id' => $event->actor_user_id,
            ];
        }

        return $payload;
    }

    private static function effectiveCostDisplay(
        OrganizationProductSource $source,
        ?OrganizationProduct $organizationProduct,
    ): ?string {
        if (
            $source->current_package_price_micro_units === null
            || $organizationProduct === null
            || $organizationProduct->purchase_unit_of_measure === null
            || $source->vendorProductOffering === null
        ) {
            return null;
        }

        $organizationProduct->loadMissing('unitConversions');

        try {
            $micro = (new VendorPackagePriceNormalizer)->normalize(
                packagePriceMicroUnits: $source->current_package_price_micro_units,
                packageQuantityScaled: $source->vendorProductOffering->package_quantity_scaled,
                offeringPurchaseUom: $source->vendorProductOffering->purchase_uom,
                organizationPurchaseUom: $organizationProduct->purchase_unit_of_measure,
                conversions: $organizationProduct->unitConversions
                    ->map(fn (OrganizationProductUnitConversion $conversion): ComponentConversionInput => new ComponentConversionInput(
                        fromUnit: $conversion->from_unit,
                        toUnit: $conversion->to_unit,
                        numerator: $conversion->numerator,
                        denominator: $conversion->denominator,
                        isActive: $conversion->is_active,
                    ))
                    ->values()
                    ->all(),
            );

            return Money::microUnitsToDollars($micro);
        } catch (InvalidComponentCostException|\InvalidArgumentException) {
            return null;
        }
    }
}
