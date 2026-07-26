<?php

namespace App\Http\Resources;

use App\Models\OrganizationProduct;
use App\Models\OrganizationProductComponent;
use App\Models\OrganizationProductUnitConversion;
use App\Models\User;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Catalog\ComponentCost\ComponentCostMapper;
use App\Support\Catalog\ComponentCost\InvalidComponentCostException;
use App\Support\Catalog\IncompleteUnitSetup;
use App\Support\Catalog\UnitConversionPreview;
use App\Support\Money;
use App\Support\Pricing\InvalidPricingException;
use App\Support\Pricing\PricingCalculator;
use App\Support\Pricing\PricingInput;
use App\Support\Pricing\PricingResult;
use Illuminate\Support\Collection;

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

        if (! $organizationProduct->relationLoaded('components')) {
            $organizationProduct->load([
                'components' => fn ($query) => $query
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->with(['componentOrganizationProduct.product', 'componentOrganizationProduct.unitConversions']),
            ]);
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

        $activeComponents = $organizationProduct->components->where('is_active', true)->values();
        $hasActiveComponents = $activeComponents->isNotEmpty();
        $materialCostSource = $hasActiveComponents ? 'components' : 'manual';

        $estimateMicroUnits = null;
        $estimateStale = false;

        if ($hasActiveComponents) {
            $estimateMicroUnits = self::tryEstimateMaterialCost($organizationProduct, $activeComponents);
            $estimateStale = $estimateMicroUnits !== null
                && $estimateMicroUnits !== $organizationProduct->material_cost_micro_units;
        }

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
            'components_version' => $organizationProduct->components_version,
            'preferred_source_id' => $organizationProduct->preferred_source_id,
            'material_cost_source' => $materialCostSource,
            'estimate_stale' => $estimateStale,
            'currency_code' => $organizationProduct->currency_code,
            'unit_selling_price' => (! $isSellable || $calculated === null)
                ? null
                : Money::centsToDollars($calculated->finalUnitPriceCents),
            'unit_setup_incomplete' => $unitSetupIncomplete,
            'unit_setup_warning' => $unitSetupIncomplete ? IncompleteUnitSetup::warningMessage() : null,
            'unit_conversions' => $conversions,
            'components' => $organizationProduct->components
                ->values()
                ->map(fn (OrganizationProductComponent $component): array => self::componentPayload(
                    $organizationProduct,
                    $component,
                    $canViewCost,
                ))
                ->all(),
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
                'notes' => $canViewCost ? $product->notes : null,
                'category' => $product->relationLoaded('category') && $product->category
                    ? ['id' => $product->category->id, 'name' => $product->category->name]
                    : null,
            ],
        ];

        if ($canViewCost) {
            $payload['purchase_cost'] = $organizationProduct->purchase_cost_micro_units !== null
                ? Money::microUnitsToDollars($organizationProduct->purchase_cost_micro_units)
                : null;
            $payload['material_cost'] = Money::microUnitsToDollars($organizationProduct->material_cost_micro_units);
            $payload['estimated_material_cost'] = $estimateMicroUnits !== null
                ? Money::microUnitsToDollars($estimateMicroUnits)
                : null;
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
    private static function componentPayload(
        OrganizationProduct $finished,
        OrganizationProductComponent $component,
        bool $canViewCost,
    ): array {
        $material = $component->componentOrganizationProduct;
        $payload = [
            'id' => $component->id,
            'component_organization_product_id' => $component->component_organization_product_id,
            'quantity' => ComponentCostEstimator::scaledToQuantity($component->quantity_scaled),
            'quantity_scaled' => $component->quantity_scaled,
            'usage_uom' => $component->usage_uom->value,
            'usage_uom_label' => $component->usage_uom->label(),
            'waste_basis_points' => $component->waste_basis_points,
            'waste_percent' => Money::basisPointsToPercent($component->waste_basis_points),
            'sort_order' => $component->sort_order,
            'is_active' => $component->is_active,
            'material' => $material === null ? null : [
                'id' => $material->id,
                'display_name' => $material->display_name ?: $material->product?->name,
                'sku' => $material->product?->sku,
                'item_kind' => $material->product?->item_kind->value,
                'is_purchasable' => $material->is_purchasable,
                'is_available' => $material->is_available,
                'purchase_unit_of_measure' => $material->purchase_unit_of_measure?->value,
                'purchase_unit_of_measure_label' => $material->purchase_unit_of_measure?->label(),
            ],
            'estimate_notice' => 'Estimated usage for costing. This does not reduce inventory or change QuickBooks quantities.',
        ];

        if ($canViewCost && $material !== null) {
            $payload['material']['purchase_cost'] = $material->purchase_cost_micro_units !== null
                ? Money::microUnitsToDollars($material->purchase_cost_micro_units)
                : null;
        }

        if ($component->is_active && $material !== null) {
            try {
                $breakdown = (new ComponentCostEstimator)->estimate(
                    (new ComponentCostMapper)->toEstimateInput($finished, collect([$component])),
                )->breakdowns[0] ?? null;

                if ($breakdown !== null) {
                    $payload['waste_adjusted_quantity'] = ComponentCostEstimator::scaledToQuantity(
                        $breakdown->wasteAdjustedQuantityScaled,
                    );
                    $payload['converted_purchase_quantity'] = $breakdown->convertedPurchaseQuantity;
                    $payload['purchase_unit_of_measure'] = $breakdown->purchaseUnitOfMeasure->value;
                    $payload['purchase_unit_of_measure_label'] = $breakdown->purchaseUnitOfMeasure->label();
                    $payload['conversion_direction'] = $breakdown->conversionDirection->value;

                    if ($canViewCost) {
                        $payload['estimated_component_cost'] = Money::microUnitsToDollars(
                            $breakdown->estimatedComponentCostMicroUnits,
                        );
                    }
                }
            } catch (InvalidComponentCostException $exception) {
                $payload['estimate_error'] = $exception->getMessage();
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

    /**
     * @param  Collection<int, OrganizationProductComponent>  $components
     */
    private static function tryEstimateMaterialCost(OrganizationProduct $organizationProduct, $components): ?int
    {
        try {
            return (new ComponentCostEstimator)->estimate(
                (new ComponentCostMapper)->toEstimateInput($organizationProduct, $components),
            )->totalEstimatedMaterialCostMicroUnits;
        } catch (InvalidComponentCostException) {
            return null;
        }
    }

    private static function tryCalculate(OrganizationProduct $organizationProduct): ?PricingResult
    {
        try {
            $base = $organizationProduct->toPricingInput();

            $activeComponents = $organizationProduct->relationLoaded('components')
                ? $organizationProduct->components->where('is_active', true)->values()
                : collect();

            $materialCost = $base->materialCostMicroUnits;

            if ($activeComponents->isNotEmpty()) {
                $estimate = self::tryEstimateMaterialCost($organizationProduct, $activeComponents);
                if ($estimate === null) {
                    return null;
                }
                $materialCost = $estimate;
            }

            $input = new PricingInput(
                materialCostMicroUnits: $materialCost,
                laborCostMicroUnits: $base->laborCostMicroUnits,
                overheadMode: $base->overheadMode,
                overheadAmountMicroUnits: $base->overheadAmountMicroUnits,
                overheadRateBasisPoints: $base->overheadRateBasisPoints,
                pricingMethod: $base->pricingMethod,
                markupBasisPoints: $base->markupBasisPoints,
                targetMarginBasisPoints: $base->targetMarginBasisPoints,
                fixedPriceCents: $base->fixedPriceCents,
                minimumPriceCents: $base->minimumPriceCents,
                allowPriceOverride: $base->allowPriceOverride,
                requestedOverridePriceCents: $base->requestedOverridePriceCents,
                quantity: $base->quantity,
                currencyCode: $base->currencyCode,
                pricingVersion: $base->pricingVersion,
            );

            return (new PricingCalculator)->calculate($input);
        } catch (InvalidPricingException) {
            return null;
        }
    }
}
