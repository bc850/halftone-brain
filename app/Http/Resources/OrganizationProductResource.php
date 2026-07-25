<?php

namespace App\Http\Resources;

use App\Models\OrganizationProduct;
use App\Models\OrganizationProductUnitConversion;
use App\Models\User;
use App\Support\Catalog\IncompleteUnitSetup;
use App\Support\Catalog\UnitConversionPreview;
use App\Support\Money;
use App\Support\Pricing\InvalidPricingException;
use App\Support\Pricing\PricingCalculator;
use App\Support\Pricing\PricingResult;

final class OrganizationProductResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(OrganizationProduct $organizationProduct, User $user): array
    {
        $canViewCost = $user->can('viewCost', $organizationProduct);
        $product = $organizationProduct->product;
        $isSellable = $organizationProduct->is_sellable;

        $calculated = $isSellable ? self::tryCalculate($organizationProduct) : null;

        $displayName = $organizationProduct->display_name ?: $product->name;

        if (! $organizationProduct->relationLoaded('unitConversions')) {
            $organizationProduct->load('unitConversions');
        }

        $conversions = $organizationProduct->unitConversions
            ->sortByDesc('id')
            ->values()
            ->map(fn (OrganizationProductUnitConversion $conversion): array => self::conversionPayload($conversion))
            ->all();

        $unitSetupIncomplete = IncompleteUnitSetup::applies(
            $organizationProduct,
            $organizationProduct->unitConversions,
        );

        $payload = [
            'id' => $organizationProduct->id,
            'display_name' => $displayName,
            'is_available' => $organizationProduct->is_available,
            'is_sellable' => $organizationProduct->is_sellable,
            'is_purchasable' => $organizationProduct->is_purchasable,
            'inventory_tracking_mode' => $organizationProduct->inventory_tracking_mode->value,
            'inventory_tracking_mode_label' => $organizationProduct->inventory_tracking_mode->label(),
            'purchase_unit_of_measure' => $organizationProduct->purchase_unit_of_measure?->value,
            'stock_unit_of_measure' => $organizationProduct->stock_unit_of_measure?->value,
            'usage_unit_of_measure' => $organizationProduct->usage_unit_of_measure?->value,
            'lead_time_days' => $organizationProduct->lead_time_days,
            'organization_notes' => $organizationProduct->notes,
            'pricing_method' => $organizationProduct->pricing_method->value,
            'pricing_version' => $organizationProduct->pricing_version,
            'currency_code' => $organizationProduct->currency_code,
            'unit_selling_price' => (! $isSellable || $calculated === null)
                ? null
                : Money::centsToDollars($calculated->finalUnitPriceCents),
            'unit_setup_incomplete' => $unitSetupIncomplete,
            'unit_setup_warning' => $unitSetupIncomplete ? IncompleteUnitSetup::warningMessage() : null,
            'unit_conversions' => $conversions,
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'product_family' => $product->product_family->value,
                'item_kind' => $product->item_kind->value,
                'item_kind_label' => $product->item_kind->label(),
                'unit_of_measure' => $product->unit_of_measure->value,
                'description' => $product->description,
                'is_active' => $product->is_active,
                'vendor_sku' => $canViewCost ? $product->vendor_sku : null,
                'notes' => $canViewCost ? $product->notes : null,
                'vendor' => $product->relationLoaded('vendor') && $product->vendor
                    ? ['id' => $product->vendor->id, 'name' => $product->vendor->name]
                    : null,
                'category' => $product->relationLoaded('category') && $product->category
                    ? ['id' => $product->category->id, 'name' => $product->category->name]
                    : null,
            ],
        ];

        if ($canViewCost) {
            $payload['material_cost'] = Money::microUnitsToDollars($organizationProduct->material_cost_micro_units);
            $payload['labor_cost'] = Money::microUnitsToDollars($organizationProduct->labor_cost_micro_units);
            $payload['overhead_mode'] = $organizationProduct->overhead_mode->value;
            $payload['overhead_amount'] = Money::microUnitsToDollars($organizationProduct->overhead_amount_micro_units);
            $payload['overhead_rate_percent'] = Money::basisPointsToPercent($organizationProduct->overhead_rate_basis_points);
            $payload['markup_percent'] = Money::basisPointsToPercent($organizationProduct->markup_basis_points);
            $payload['target_margin_percent'] = Money::basisPointsToPercent($organizationProduct->target_margin_basis_points);
            $payload['fixed_price'] = $organizationProduct->fixed_price_cents !== null
                ? Money::centsToDollars($organizationProduct->fixed_price_cents)
                : null;
            $payload['minimum_price'] = $organizationProduct->minimum_price_cents !== null
                ? Money::centsToDollars($organizationProduct->minimum_price_cents)
                : null;
            $payload['allow_price_override'] = $organizationProduct->allow_price_override;

            if ($isSellable) {
                $payload['unit_cost'] = $calculated === null
                    ? null
                    : Money::microUnitsToDollars($calculated->totalUnitCostMicroUnits);
                $payload['below_minimum'] = $calculated === null
                    ? false
                    : $calculated->belowMinimum;
                $payload['pricing_warnings'] = $calculated === null
                    ? []
                    : $calculated->warnings;
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function conversionPayload(OrganizationProductUnitConversion $conversion): array
    {
        $from = $conversion->from_unit;
        $to = $conversion->to_unit;

        $preview = UnitConversionPreview::make(
            $from->value,
            $to->value,
            $conversion->numerator,
            $conversion->denominator,
        );

        return [
            'id' => $conversion->id,
            'from_unit' => $from->value,
            'from_unit_label' => $from->label(),
            'to_unit' => $to->value,
            'to_unit_label' => $to->label(),
            'numerator' => $conversion->numerator,
            'denominator' => $conversion->denominator,
            'is_active' => $conversion->is_active,
            'preview' => $preview['preview'],
            'derived_reciprocal' => $preview['derived_reciprocal'],
            'converted_one' => $preview['converted_one'],
        ];
    }

    private static function tryCalculate(OrganizationProduct $organizationProduct): ?PricingResult
    {
        try {
            return (new PricingCalculator)->calculate($organizationProduct->toPricingInput());
        } catch (InvalidPricingException) {
            return null;
        }
    }
}
