<?php

namespace App\Support\Catalog\ComponentCost;

use App\Enums\ItemKind;
use App\Enums\UnitOfMeasure;
use App\Models\OrganizationProduct;
use App\Models\OrganizationProductComponent;
use App\Models\OrganizationProductUnitConversion;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Maps Eloquent catalog rows into immutable ComponentCostEstimator inputs.
 * Performs no writes.
 */
final class ComponentCostMapper
{
    /**
     * @param  Collection<int, OrganizationProductComponent>|list<OrganizationProductComponent>  $components
     */
    public function toEstimateInput(
        OrganizationProduct $finished,
        Collection|array $components,
    ): ComponentCostEstimateInput {
        $finished->loadMissing('product');

        $lines = [];
        foreach ($components as $component) {
            $lines[] = $this->toLineInput($finished, $component);
        }

        return new ComponentCostEstimateInput(
            organizationProductId: $finished->id,
            parentAccountId: $finished->parent_account_id,
            organizationId: $finished->organization_id,
            itemKind: $finished->product->item_kind,
            isSellable: $finished->is_sellable,
            components: $lines,
        );
    }

    public function toLineInput(
        OrganizationProduct $finished,
        OrganizationProductComponent $component,
    ): ComponentLineInput {
        $component->loadMissing(['componentOrganizationProduct.product', 'componentOrganizationProduct.unitConversions']);

        $material = $component->componentOrganizationProduct;
        if ($material === null) {
            throw ValidationException::withMessages([
                'component_organization_product_id' => 'Component material was not found.',
            ]);
        }

        if (
            $component->organization_id !== $finished->organization_id
            || $component->parent_account_id !== $finished->parent_account_id
            || $material->organization_id !== $finished->organization_id
            || $material->parent_account_id !== $finished->parent_account_id
        ) {
            abort(404);
        }

        $material->loadMissing(['product', 'unitConversions']);

        return new ComponentLineInput(
            componentOrganizationProductId: $material->id,
            parentAccountId: $material->parent_account_id,
            organizationId: $material->organization_id,
            itemKind: $material->product->item_kind,
            isPurchasable: $material->is_purchasable,
            purchaseUnitOfMeasure: $material->purchase_unit_of_measure,
            purchaseCostMicroUnits: $material->purchase_cost_micro_units,
            quantityScaled: $component->quantity_scaled,
            usageUnitOfMeasure: $component->usage_uom,
            wasteBasisPoints: $component->waste_basis_points,
            conversions: $this->conversionInputs($material->unitConversions),
        );
    }

    /**
     * Estimate eligibility reason for a candidate material OP, or null if eligible.
     */
    public function materialIneligibilityReason(
        OrganizationProduct $material,
        UnitOfMeasure $usageUnit,
    ): ?string {
        $material->loadMissing(['product', 'unitConversions']);

        if ($material->product->item_kind !== ItemKind::Material) {
            return 'Product master is not a material.';
        }

        if (! $material->is_purchasable) {
            return 'Not purchasable.';
        }

        if (! $material->is_available) {
            return 'Unavailable.';
        }

        if ($material->purchase_unit_of_measure === null) {
            return 'Missing purchase unit.';
        }

        if ($material->purchase_cost_micro_units === null) {
            return 'Missing purchase cost.';
        }

        try {
            $this->assertConvertible($material, $usageUnit);
        } catch (InvalidComponentCostException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'disagree')) {
                return 'Conflicting conversions.';
            }

            return 'Missing conversion.';
        } catch (ValidationException) {
            return 'Missing conversion.';
        }

        return null;
    }

    /**
     * @throws ValidationException|InvalidComponentCostException
     */
    public function assertConvertible(OrganizationProduct $material, UnitOfMeasure $usageUnit): void
    {
        $purchaseUnit = $material->purchase_unit_of_measure;
        if ($purchaseUnit === null) {
            throw ValidationException::withMessages([
                'usage_uom' => 'Component purchase unit of measure is required.',
            ]);
        }

        $material->loadMissing('unitConversions');

        (new ComponentCostEstimator)->resolveUsageToPurchase(
            $usageUnit,
            $purchaseUnit,
            $this->conversionInputs($material->unitConversions),
        );
    }

    /**
     * @param  Collection<int, OrganizationProductUnitConversion>  $conversions
     * @return array<int, ComponentConversionInput>
     */
    private function conversionInputs(Collection $conversions): array
    {
        return $conversions
            ->map(fn (OrganizationProductUnitConversion $conversion): ComponentConversionInput => new ComponentConversionInput(
                fromUnit: $conversion->from_unit,
                toUnit: $conversion->to_unit,
                numerator: $conversion->numerator,
                denominator: $conversion->denominator,
                isActive: $conversion->is_active,
            ))
            ->values()
            ->all();
    }
}
