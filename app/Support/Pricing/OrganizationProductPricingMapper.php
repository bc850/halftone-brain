<?php

namespace App\Support\Pricing;

use App\Models\OrganizationProduct;

/**
 * Maps an OrganizationProduct into an immutable PricingInput.
 *
 * Does not read Product Master legacy cost/price fields.
 * Does not write to the database or mutate pricing_version.
 */
final class OrganizationProductPricingMapper
{
    public function toPricingInput(
        OrganizationProduct $organizationProduct,
        string $quantity = '1',
        ?int $requestedOverridePriceCents = null,
    ): PricingInput {
        if ($organizationProduct->parent_account_id < 1
            || $organizationProduct->organization_id < 1
            || $organizationProduct->product_id < 1) {
            throw new InvalidPricingException(
                'Organization product is missing required tenant or product identity.'
            );
        }

        return new PricingInput(
            materialCostMicroUnits: $organizationProduct->material_cost_micro_units,
            laborCostMicroUnits: $organizationProduct->labor_cost_micro_units,
            overheadMode: $organizationProduct->overhead_mode,
            overheadAmountMicroUnits: $organizationProduct->overhead_amount_micro_units,
            overheadRateBasisPoints: $organizationProduct->overhead_rate_basis_points,
            pricingMethod: $organizationProduct->pricing_method,
            markupBasisPoints: $organizationProduct->markup_basis_points,
            targetMarginBasisPoints: $organizationProduct->target_margin_basis_points,
            fixedPriceCents: $organizationProduct->fixed_price_cents,
            minimumPriceCents: $organizationProduct->minimum_price_cents,
            allowPriceOverride: $organizationProduct->allow_price_override,
            requestedOverridePriceCents: $requestedOverridePriceCents,
            quantity: $quantity,
            currencyCode: $organizationProduct->currency_code,
            pricingVersion: $organizationProduct->pricing_version,
        );
    }
}
