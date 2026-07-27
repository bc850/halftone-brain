<?php

namespace App\Support\Quotes;

use App\Enums\QuoteLineType;
use App\Models\OrganizationProduct;
use App\Models\OrganizationProductComponent;
use App\Models\Quote;
use App\Models\QuoteRevisionLineItem;
use App\Support\Catalog\ComponentCost\ComponentCostBreakdown;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Catalog\ComponentCost\ComponentCostMapper;
use App\Support\Catalog\ComponentCost\InvalidComponentCostException;
use App\Support\Pricing\InvalidPricingException;
use App\Support\Pricing\PricingCalculator;
use App\Support\Pricing\PricingResult;
use Illuminate\Database\Eloquent\Collection;

/**
 * Prices a catalog organization product into quote line attributes.
 *
 * Material cost sourcing: {@see OrganizationProduct::toPricingInput()} reads the persisted
 * `material_cost_micro_units`, which OrganizationProductCatalogService::updatePricing already
 * overwrites from the active component estimate whenever components exist. Re-estimating here
 * would silently price off an unsaved catalog state, so the estimate is captured only as the
 * `component_cost_breakdown_json` transparency snapshot alongside `components_version_snapshot`.
 */
final class QuoteCatalogLinePricer
{
    public function __construct(
        private PricingCalculator $pricing,
        private ComponentCostMapper $componentMapper,
        private ComponentCostEstimator $componentEstimator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function priceForNewLine(
        Quote $quote,
        OrganizationProduct $organizationProduct,
        string $quantity,
        ?int $overrideUnitPriceCents = null,
        ?string $overrideReason = null,
        bool $isTaxable = true,
        ?string $customerDescription = null,
        ?string $internalDescription = null,
    ): array {
        $this->assertSellable($quote, $organizationProduct);

        $result = $this->calculate($organizationProduct, $quantity, $overrideUnitPriceCents);

        return $this->toAttributes(
            organizationProduct: $organizationProduct,
            quantity: $quantity,
            result: $result,
            overrideReason: $overrideReason,
            isTaxable: $isTaxable,
            customerDescription: $customerDescription,
            internalDescription: $internalDescription,
        );
    }

    /**
     * Refresh a catalog line against current catalog pricing.
     *
     * With `$preserveOverride` the customer-facing final price stays untouched while the
     * calculated price, costs, and version snapshots move to the current catalog state.
     *
     * @return array<string, mixed>
     */
    public function priceForReprice(
        Quote $quote,
        QuoteRevisionLineItem $line,
        OrganizationProduct $organizationProduct,
        bool $preserveOverride = true,
    ): array {
        if ($line->line_type !== QuoteLineType::Catalog) {
            throw new InvalidQuoteDraftException('Only catalog lines can be repriced.');
        }

        $this->assertSellable($quote, $organizationProduct);

        $quantity = ComponentCostEstimator::scaledToQuantity($line->quantity_scaled);
        $override = $preserveOverride && $line->price_override
            ? $line->final_unit_price_cents
            : null;

        $result = $this->calculate($organizationProduct, $quantity, $override);

        return $this->toAttributes(
            organizationProduct: $organizationProduct,
            quantity: $quantity,
            result: $result,
            overrideReason: $result->overrideApplied ? $line->override_reason : null,
            isTaxable: $line->is_taxable,
            customerDescription: $line->customer_description_snapshot,
            internalDescription: $line->internal_description_snapshot,
        );
    }

    public function assertSellable(Quote $quote, OrganizationProduct $organizationProduct): void
    {
        if ($organizationProduct->organization_id !== $quote->organization_id
            || $organizationProduct->parent_account_id !== $quote->parent_account_id) {
            throw new InvalidQuoteDraftException('Catalog item does not belong to the quote organization.');
        }

        $organizationProduct->loadMissing('product');

        if (! $organizationProduct->is_sellable) {
            throw new InvalidQuoteDraftException('Catalog item is not sellable.');
        }

        if (! $organizationProduct->is_available) {
            throw new InvalidQuoteDraftException('Catalog item is not available.');
        }

        if (! $organizationProduct->product->is_active) {
            throw new InvalidQuoteDraftException('Catalog item product master is inactive.');
        }
    }

    private function calculate(
        OrganizationProduct $organizationProduct,
        string $quantity,
        ?int $overrideUnitPriceCents,
    ): PricingResult {
        try {
            return $this->pricing->calculate(
                $organizationProduct->toPricingInput($quantity, $overrideUnitPriceCents)
            );
        } catch (InvalidPricingException $exception) {
            throw new InvalidQuoteDraftException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toAttributes(
        OrganizationProduct $organizationProduct,
        string $quantity,
        PricingResult $result,
        ?string $overrideReason,
        bool $isTaxable,
        ?string $customerDescription,
        ?string $internalDescription,
    ): array {
        $product = $organizationProduct->product;

        if ($result->overrideApplied && ($overrideReason === null || trim($overrideReason) === '')) {
            throw new InvalidQuoteDraftException('A price override requires a reason.');
        }

        $approvalRequired = $result->overrideApplied || $result->belowMinimum;

        $reasons = [];
        if ($result->overrideApplied) {
            $reasons[] = QuoteApprovalReasonAggregator::REASON_PRICE_OVERRIDE;

            if ($result->finalUnitPriceCents < $result->calculatedUnitPriceCents) {
                $reasons[] = QuoteApprovalReasonAggregator::REASON_MARGIN_OVERRIDE;
            }
        }
        if ($result->belowMinimum) {
            $reasons[] = QuoteApprovalReasonAggregator::REASON_BELOW_MINIMUM;
        }

        return [
            'line_type' => QuoteLineType::Catalog,
            'product_id' => $organizationProduct->product_id,
            'organization_product_id' => $organizationProduct->id,
            'sku_snapshot' => $product->sku,
            'name_snapshot' => $organizationProduct->display_name ?? $product->name,
            'customer_description_snapshot' => $customerDescription ?? $product->description,
            'internal_description_snapshot' => $internalDescription,
            'item_kind_snapshot' => $product->item_kind->value,
            'quantity_scaled' => ComponentCostEstimator::quantityToScaled($quantity),
            'uom_snapshot' => $product->unit_of_measure->value,
            'calculated_unit_price_cents' => $result->calculatedUnitPriceCents,
            'final_unit_price_cents' => $result->finalUnitPriceCents,
            'is_taxable' => $isTaxable,
            'price_override' => $result->overrideApplied,
            'override_reason' => $result->overrideApplied ? $overrideReason : null,
            'below_minimum' => $result->belowMinimum,
            'approval_required' => $approvalRequired,
            'approval_reason_json' => $reasons === [] ? null : ['reasons' => $reasons],
            'material_cost_micro_units' => $result->materialCostMicroUnits,
            'labor_cost_micro_units' => $result->laborCostMicroUnits,
            'overhead_cost_micro_units' => $result->overheadCostMicroUnits,
            'total_cost_micro_units' => $result->totalUnitCostMicroUnits,
            'pricing_method_snapshot' => $result->pricingMethod->value,
            'markup_basis_points_snapshot' => $result->markupBasisPoints,
            'margin_basis_points_snapshot' => $result->targetMarginBasisPoints,
            'pricing_version_snapshot' => $organizationProduct->pricing_version,
            'components_version_snapshot' => $organizationProduct->components_version,
            'component_cost_breakdown_json' => $this->componentBreakdown($organizationProduct),
            'pricing_input_snapshot_json' => [
                'quantity' => $result->quantity,
                'material_cost_micro_units' => $result->materialCostMicroUnits,
                'labor_cost_micro_units' => $result->laborCostMicroUnits,
                'overhead_mode' => $result->overheadMode->value,
                'overhead_amount_micro_units' => $result->overheadAmountMicroUnits,
                'overhead_rate_basis_points' => $result->overheadRateBasisPoints,
                'pricing_method' => $result->pricingMethod->value,
                'markup_basis_points' => $result->markupBasisPoints,
                'target_margin_basis_points' => $result->targetMarginBasisPoints,
                'fixed_price_cents' => $result->fixedPriceCents,
                'minimum_price_cents' => $result->minimumPriceCents,
                'requested_override_price_cents' => $result->requestedOverridePriceCents,
                'currency_code' => $result->currencyCode,
                'pricing_version' => $organizationProduct->pricing_version,
                'components_version' => $organizationProduct->components_version,
            ],
            'pricing_result_snapshot_json' => [
                'total_unit_cost_micro_units' => $result->totalUnitCostMicroUnits,
                'calculated_unit_price_cents' => $result->calculatedUnitPriceCents,
                'final_unit_price_cents' => $result->finalUnitPriceCents,
                'extended_price_cents' => $result->extendedPriceCents,
                'override_applied' => $result->overrideApplied,
                'below_minimum' => $result->belowMinimum,
                'approval_required' => $approvalRequired,
                'warnings' => $result->warnings,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function componentBreakdown(OrganizationProduct $organizationProduct): ?array
    {
        $components = $this->activeComponents($organizationProduct);

        if ($components->isEmpty()) {
            return null;
        }

        try {
            $estimate = $this->componentEstimator->estimate(
                $this->componentMapper->toEstimateInput($organizationProduct, $components)
            );
        } catch (InvalidComponentCostException) {
            // A broken component graph must not block quoting; the persisted catalog
            // material cost still drives price, so record the gap instead of failing.
            return ['unavailable' => true];
        }

        return [
            'total_estimated_material_cost_micro_units' => $estimate->totalEstimatedMaterialCostMicroUnits,
            'components' => array_map(
                static fn (ComponentCostBreakdown $breakdown): array => [
                    'component_organization_product_id' => $breakdown->componentOrganizationProductId,
                    'base_usage_quantity_scaled' => $breakdown->baseUsageQuantityScaled,
                    'waste_basis_points' => $breakdown->wasteBasisPoints,
                    'waste_adjusted_quantity_scaled' => $breakdown->wasteAdjustedQuantityScaled,
                    'usage_unit_of_measure' => $breakdown->usageUnitOfMeasure->value,
                    'converted_purchase_quantity' => $breakdown->convertedPurchaseQuantity,
                    'purchase_unit_of_measure' => $breakdown->purchaseUnitOfMeasure->value,
                    'purchase_cost_micro_units' => $breakdown->purchaseCostMicroUnits,
                    'estimated_component_cost_micro_units' => $breakdown->estimatedComponentCostMicroUnits,
                ],
                $estimate->breakdowns,
            ),
        ];
    }

    /**
     * @return Collection<int, OrganizationProductComponent>
     */
    private function activeComponents(OrganizationProduct $organizationProduct): Collection
    {
        return OrganizationProductComponent::query()
            ->with(['componentOrganizationProduct.product', 'componentOrganizationProduct.unitConversions'])
            ->where('organization_product_id', $organizationProduct->id)
            ->where('organization_id', $organizationProduct->organization_id)
            ->where('parent_account_id', $organizationProduct->parent_account_id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}
