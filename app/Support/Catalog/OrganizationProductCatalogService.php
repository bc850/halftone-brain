<?php

namespace App\Support\Catalog;

use App\Enums\InventoryTrackingMode;
use App\Enums\ItemKind;
use App\Enums\OverheadMode;
use App\Enums\PricingMethod;
use App\Models\OrganizationProduct;
use App\Models\OrganizationProductComponent;
use App\Models\ParentAccount;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Catalog\ComponentCost\ComponentCostMapper;
use App\Support\Catalog\ComponentCost\ComponentDependencyVersionService;
use App\Support\Catalog\ComponentCost\InvalidComponentCostException;
use App\Support\Pricing\InvalidPricingException;
use App\Support\Pricing\PricingCalculator;
use App\Support\Pricing\PricingInput;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Transactional organization catalog mutations with audited pricing-version control.
 */
final class OrganizationProductCatalogService
{
    public function __construct(
        private Auditor $auditor,
        private ComponentDependencyVersionService $componentVersions,
        private ComponentCostMapper $componentCostMapper,
        private ComponentCostEstimator $componentCostEstimator,
    ) {}

    /**
     * @param  array<string, mixed>  $masterData
     * @param  array<string, mixed>  $organizationData
     */
    public function createMasterWithOrganizationProduct(
        TenantContext $tenant,
        User $actor,
        Request $request,
        array $masterData,
        array $organizationData,
    ): OrganizationProduct {
        return DB::transaction(function () use ($tenant, $actor, $request, $masterData, $organizationData): OrganizationProduct {
            $parent = ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail();
            $organization = $tenant->organization;

            $product = Product::query()->create([
                'parent_account_id' => $tenant->parentAccountId,
                'name' => $masterData['name'],
                'product_family' => $masterData['product_family'],
                'item_kind' => $masterData['item_kind'] ?? ItemKind::Product->value,
                'sku' => $masterData['sku'],
                'vendor_sku' => $masterData['vendor_sku'] ?? null,
                'vendor_id' => $masterData['vendor_id'] ?? null,
                'product_category_id' => $masterData['product_category_id'] ?? null,
                'unit_of_measure' => $masterData['unit_of_measure'],
                'description' => $masterData['description'] ?? null,
                'notes' => $masterData['notes'] ?? null,
                'is_active' => (bool) ($masterData['is_active'] ?? true),
                // Legacy shared pricing columns retained but unused by org catalog pricing.
                'true_cost_micro_units' => 0,
                'markup_basis_points' => 0,
                'list_price_cents' => null,
            ]);

            $organizationProduct = OrganizationProduct::query()->create([
                'parent_account_id' => $tenant->parentAccountId,
                'organization_id' => $tenant->organizationId,
                'product_id' => $product->id,
                'display_name' => $organizationData['display_name'] ?? null,
                'is_available' => (bool) ($organizationData['is_available'] ?? true),
                'is_sellable' => (bool) ($organizationData['is_sellable'] ?? true),
                'is_purchasable' => (bool) ($organizationData['is_purchasable'] ?? false),
                'inventory_tracking_mode' => $organizationData['inventory_tracking_mode'] ?? InventoryTrackingMode::None->value,
                'purchase_unit_of_measure' => $organizationData['purchase_unit_of_measure'] ?? null,
                'stock_unit_of_measure' => $organizationData['stock_unit_of_measure'] ?? null,
                'usage_unit_of_measure' => $organizationData['usage_unit_of_measure'] ?? null,
                'lead_time_days' => $organizationData['lead_time_days'] ?? null,
                'notes' => $organizationData['organization_notes'] ?? null,
                'material_cost_micro_units' => $organizationData['material_cost_micro_units'],
                'labor_cost_micro_units' => $organizationData['labor_cost_micro_units'],
                'overhead_mode' => $organizationData['overhead_mode'],
                'overhead_amount_micro_units' => $organizationData['overhead_amount_micro_units'],
                'overhead_rate_basis_points' => $organizationData['overhead_rate_basis_points'],
                'pricing_method' => $organizationData['pricing_method'],
                'markup_basis_points' => $organizationData['markup_basis_points'],
                'target_margin_basis_points' => $organizationData['target_margin_basis_points'],
                'fixed_price_cents' => $organizationData['fixed_price_cents'],
                'minimum_price_cents' => $organizationData['minimum_price_cents'],
                'allow_price_override' => (bool) $organizationData['allow_price_override'],
                'currency_code' => PricingCalculator::CURRENCY_USD,
                'pricing_version' => 1,
                'components_version' => 1,
            ]);

            $this->auditor->append(
                parentAccount: $parent,
                action: 'catalog.product_master.created',
                subjectType: Product::class,
                subjectId: $product->id,
                organization: $organization,
                actor: $actor,
                after: $this->masterAuditPayload($product),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            $this->auditor->append(
                parentAccount: $parent,
                action: 'catalog.organization_product.created',
                subjectType: OrganizationProduct::class,
                subjectId: $organizationProduct->id,
                organization: $organization,
                actor: $actor,
                after: $this->organizationProductAuditPayload($organizationProduct),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $organizationProduct->load('product');
        });
    }

    /**
     * @param  array<string, mixed>  $organizationData
     */
    public function associateExistingMaster(
        TenantContext $tenant,
        User $actor,
        Request $request,
        Product $product,
        array $organizationData,
        bool $pricingComplete,
    ): OrganizationProduct {
        if ($product->parent_account_id !== $tenant->parentAccountId) {
            throw ValidationException::withMessages([
                'product_id' => 'The selected product does not belong to this parent account.',
            ]);
        }

        if (! $product->is_active) {
            throw ValidationException::withMessages([
                'product_id' => 'Only active product masters can be added to the organization catalog.',
            ]);
        }

        $exists = OrganizationProduct::query()
            ->where('organization_id', $tenant->organizationId)
            ->where('product_id', $product->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'product_id' => 'This product is already in the organization catalog.',
            ]);
        }

        return DB::transaction(function () use ($tenant, $actor, $request, $product, $organizationData, $pricingComplete): OrganizationProduct {
            $parent = ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail();

            $organizationProduct = OrganizationProduct::query()->create([
                'parent_account_id' => $tenant->parentAccountId,
                'organization_id' => $tenant->organizationId,
                'product_id' => $product->id,
                'display_name' => $organizationData['display_name'] ?? null,
                'is_available' => $pricingComplete ? (bool) ($organizationData['is_available'] ?? true) : false,
                'is_sellable' => (bool) ($organizationData['is_sellable'] ?? true),
                'is_purchasable' => (bool) ($organizationData['is_purchasable'] ?? false),
                'inventory_tracking_mode' => $organizationData['inventory_tracking_mode'] ?? InventoryTrackingMode::None->value,
                'purchase_unit_of_measure' => $organizationData['purchase_unit_of_measure'] ?? null,
                'stock_unit_of_measure' => $organizationData['stock_unit_of_measure'] ?? null,
                'usage_unit_of_measure' => $organizationData['usage_unit_of_measure'] ?? null,
                'lead_time_days' => $organizationData['lead_time_days'] ?? null,
                'notes' => $organizationData['organization_notes'] ?? null,
                'material_cost_micro_units' => $organizationData['material_cost_micro_units'] ?? 0,
                'labor_cost_micro_units' => $organizationData['labor_cost_micro_units'] ?? 0,
                'overhead_mode' => $organizationData['overhead_mode'] ?? OverheadMode::None->value,
                'overhead_amount_micro_units' => $organizationData['overhead_amount_micro_units'] ?? 0,
                'overhead_rate_basis_points' => $organizationData['overhead_rate_basis_points'] ?? 0,
                'pricing_method' => $organizationData['pricing_method'] ?? PricingMethod::Markup->value,
                'markup_basis_points' => $organizationData['markup_basis_points'] ?? 0,
                'target_margin_basis_points' => $organizationData['target_margin_basis_points'] ?? 0,
                'fixed_price_cents' => $organizationData['fixed_price_cents'] ?? null,
                'minimum_price_cents' => $organizationData['minimum_price_cents'] ?? null,
                'allow_price_override' => (bool) ($organizationData['allow_price_override'] ?? false),
                'currency_code' => PricingCalculator::CURRENCY_USD,
                'pricing_version' => 1,
                'components_version' => 1,
            ]);

            $this->auditor->append(
                parentAccount: $parent,
                action: 'catalog.organization_product.created',
                subjectType: OrganizationProduct::class,
                subjectId: $organizationProduct->id,
                organization: $tenant->organization,
                actor: $actor,
                after: $this->organizationProductAuditPayload($organizationProduct),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $organizationProduct->load('product');
        });
    }

    /**
     * @param  array<string, mixed>  $masterData
     */
    public function updateMaster(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProduct $organizationProduct,
        array $masterData,
    ): OrganizationProduct {
        $product = $organizationProduct->product()->firstOrFail();
        $before = $this->masterAuditPayload($product);
        $previousKind = $product->item_kind;

        return DB::transaction(function () use ($tenant, $actor, $request, $organizationProduct, $product, $masterData, $before, $previousKind): OrganizationProduct {
            $product->update([
                'name' => $masterData['name'],
                'product_family' => $masterData['product_family'],
                'item_kind' => $masterData['item_kind'] ?? $product->item_kind->value,
                'sku' => $masterData['sku'],
                'vendor_sku' => $masterData['vendor_sku'] ?? null,
                'vendor_id' => $masterData['vendor_id'] ?? null,
                'product_category_id' => $masterData['product_category_id'] ?? null,
                'unit_of_measure' => $masterData['unit_of_measure'],
                'description' => $masterData['description'] ?? null,
                'notes' => $masterData['notes'] ?? null,
                'is_active' => (bool) ($masterData['is_active'] ?? $product->is_active),
            ]);

            $fresh = $product->fresh() ?? $product;
            $newKind = $fresh->item_kind;

            if (
                $previousKind !== $newKind
                && ($previousKind === ItemKind::Material || $newKind === ItemKind::Material)
            ) {
                $this->componentVersions->invalidateDependentsOfProductMaster($fresh);
            }

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: 'catalog.product_master.updated',
                subjectType: Product::class,
                subjectId: $product->id,
                organization: $tenant->organization,
                actor: $actor,
                before: $before,
                after: $this->masterAuditPayload($fresh),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $organizationProduct->fresh(['product']) ?? $organizationProduct;
        });
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function updateSettings(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProduct $organizationProduct,
        array $settings,
    ): OrganizationProduct {
        $before = $this->settingsAuditPayload($organizationProduct);

        return DB::transaction(function () use ($tenant, $actor, $request, $organizationProduct, $settings, $before): OrganizationProduct {
            $previousPurchaseUom = $organizationProduct->purchase_unit_of_measure?->value;
            $previousPurchasable = $organizationProduct->is_purchasable;
            $previousAvailable = $organizationProduct->is_available;

            $organizationProduct->update([
                'display_name' => $settings['display_name'] ?? null,
                'is_available' => (bool) ($settings['is_available'] ?? $organizationProduct->is_available),
                'is_sellable' => (bool) ($settings['is_sellable'] ?? $organizationProduct->is_sellable),
                'is_purchasable' => (bool) ($settings['is_purchasable'] ?? $organizationProduct->is_purchasable),
                'inventory_tracking_mode' => $settings['inventory_tracking_mode'] ?? $organizationProduct->inventory_tracking_mode->value,
                'purchase_unit_of_measure' => $settings['purchase_unit_of_measure'] ?? null,
                'stock_unit_of_measure' => $settings['stock_unit_of_measure'] ?? null,
                'usage_unit_of_measure' => $settings['usage_unit_of_measure'] ?? null,
                'lead_time_days' => $settings['lead_time_days'] ?? null,
                'notes' => $settings['notes'] ?? null,
            ]);

            $fresh = $organizationProduct->fresh() ?? $organizationProduct;

            $shouldInvalidate = $previousPurchaseUom !== $fresh->purchase_unit_of_measure?->value
                || $previousPurchasable !== $fresh->is_purchasable
                || $previousAvailable !== $fresh->is_available;

            if ($shouldInvalidate) {
                $this->componentVersions->invalidateDependentsOfMaterial($fresh);
            }

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: 'catalog.organization_product.settings_updated',
                subjectType: OrganizationProduct::class,
                subjectId: $organizationProduct->id,
                organization: $tenant->organization,
                actor: $actor,
                before: $before,
                after: $this->settingsAuditPayload($fresh),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $fresh->load('product');
        });
    }

    /**
     * Update purchase cost for a purchasable organization product (per purchase UOM).
     */
    public function updatePurchaseCost(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProduct $organizationProduct,
        ?int $purchaseCostMicroUnits,
    ): OrganizationProduct {
        if (
            $organizationProduct->organization_id !== $tenant->organizationId
            || $organizationProduct->parent_account_id !== $tenant->parentAccountId
        ) {
            abort(404);
        }

        return DB::transaction(function () use ($tenant, $actor, $request, $organizationProduct, $purchaseCostMicroUnits): OrganizationProduct {
            $locked = OrganizationProduct::query()
                ->whereKey($organizationProduct->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($purchaseCostMicroUnits !== null) {
                if (! $locked->is_purchasable) {
                    throw ValidationException::withMessages([
                        'purchase_cost' => 'Purchase cost can only be set on purchasable products.',
                    ]);
                }

                if ($locked->purchase_unit_of_measure === null) {
                    throw ValidationException::withMessages([
                        'purchase_cost' => 'Purchase unit of measure is required when setting purchase cost.',
                    ]);
                }

                if ($purchaseCostMicroUnits < 0) {
                    throw ValidationException::withMessages([
                        'purchase_cost' => 'Purchase cost cannot be negative.',
                    ]);
                }
            }

            $before = [
                'purchase_cost_micro_units' => $locked->purchase_cost_micro_units,
                'purchase_unit_of_measure' => $locked->purchase_unit_of_measure?->value,
                'is_purchasable' => $locked->is_purchasable,
            ];

            $previousCost = $locked->purchase_cost_micro_units;
            $locked->forceFill(['purchase_cost_micro_units' => $purchaseCostMicroUnits])->save();

            if ($previousCost !== $purchaseCostMicroUnits) {
                $this->componentVersions->invalidateDependentsOfMaterial($locked);
            }

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: 'catalog.organization_product.purchase_cost_updated',
                subjectType: OrganizationProduct::class,
                subjectId: $locked->id,
                organization: $tenant->organization,
                actor: $actor,
                before: $before,
                after: [
                    'purchase_cost_micro_units' => $locked->purchase_cost_micro_units,
                    'purchase_unit_of_measure' => $locked->purchase_unit_of_measure?->value,
                    'is_purchasable' => $locked->is_purchasable,
                    'components_version' => $locked->fresh()?->components_version,
                ],
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $locked->fresh(['product']) ?? $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $pricing
     */
    public function updatePricing(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProduct $organizationProduct,
        array $pricing,
        int $expectedPricingVersion,
        int $expectedComponentsVersion,
    ): OrganizationProduct {
        return DB::transaction(function () use (
            $tenant,
            $actor,
            $request,
            $organizationProduct,
            $pricing,
            $expectedPricingVersion,
            $expectedComponentsVersion,
        ): OrganizationProduct {
            $locked = OrganizationProduct::query()
                ->whereKey($organizationProduct->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPricingAndComponentsVersions($locked, $expectedPricingVersion, $expectedComponentsVersion);

            $activeComponents = $this->activeComponentsFor($locked);
            $materialSource = $activeComponents->isNotEmpty() ? 'components' : 'manual';

            if ($activeComponents->isNotEmpty()) {
                $pricing['material_cost_micro_units'] = $this->estimateMaterialCostMicroUnits($locked, $activeComponents);
            }

            $this->assertPricingConfiguration($pricing);

            $before = $this->pricingAuditPayload($locked);
            $before['material_source'] = $materialSource;

            $locked->fill([
                'material_cost_micro_units' => $pricing['material_cost_micro_units'],
                'labor_cost_micro_units' => $pricing['labor_cost_micro_units'],
                'overhead_mode' => $pricing['overhead_mode'],
                'overhead_amount_micro_units' => $pricing['overhead_amount_micro_units'],
                'overhead_rate_basis_points' => $pricing['overhead_rate_basis_points'],
                'pricing_method' => $pricing['pricing_method'],
                'markup_basis_points' => $pricing['markup_basis_points'],
                'target_margin_basis_points' => $pricing['target_margin_basis_points'],
                'fixed_price_cents' => $pricing['fixed_price_cents'],
                'minimum_price_cents' => $pricing['minimum_price_cents'],
                'allow_price_override' => (bool) $pricing['allow_price_override'],
                'currency_code' => PricingCalculator::CURRENCY_USD,
                'pricing_version' => $locked->pricing_version + 1,
            ]);
            $locked->save();

            $after = $this->pricingAuditPayload($locked);
            $after['material_source'] = $materialSource;

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: 'catalog.organization_product.pricing_updated',
                subjectType: OrganizationProduct::class,
                subjectId: $locked->id,
                organization: $tenant->organization,
                actor: $actor,
                before: $before,
                after: $after,
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $locked->load('product');
        });
    }

    /**
     * Preview pricing with optional component re-estimate. No writes.
     *
     * @param  array<string, mixed>  $pricing
     * @return array{material_cost_micro_units: int, material_source: string}
     */
    public function resolvePreviewMaterialCost(
        OrganizationProduct $organizationProduct,
        array $pricing,
        int $expectedPricingVersion,
        int $expectedComponentsVersion,
    ): array {
        if ($organizationProduct->pricing_version !== $expectedPricingVersion) {
            throw new HttpException(
                409,
                'Pricing was updated by another user. Refresh and review the latest values before saving.'
            );
        }

        if ($organizationProduct->components_version !== $expectedComponentsVersion) {
            throw new HttpException(
                409,
                'Component costs changed. Refresh and review the latest estimate before continuing.'
            );
        }

        $activeComponents = $this->activeComponentsFor($organizationProduct);

        if ($activeComponents->isNotEmpty()) {
            return [
                'material_cost_micro_units' => $this->estimateMaterialCostMicroUnits($organizationProduct, $activeComponents),
                'material_source' => 'components',
            ];
        }

        return [
            'material_cost_micro_units' => (int) $pricing['material_cost_micro_units'],
            'material_source' => 'manual',
        ];
    }

    public function archive(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProduct $organizationProduct,
    ): OrganizationProduct {
        $before = ['is_available' => $organizationProduct->is_available];

        return DB::transaction(function () use ($tenant, $actor, $request, $organizationProduct, $before): OrganizationProduct {
            $wasAvailable = $organizationProduct->is_available;
            $organizationProduct->update(['is_available' => false]);

            if ($wasAvailable) {
                $this->componentVersions->invalidateDependentsOfMaterial($organizationProduct);
            }

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: 'catalog.organization_product.archived',
                subjectType: OrganizationProduct::class,
                subjectId: $organizationProduct->id,
                organization: $tenant->organization,
                actor: $actor,
                before: $before,
                after: [
                    'is_available' => false,
                    'pricing_version' => $organizationProduct->pricing_version,
                    'components_version' => $organizationProduct->fresh()?->components_version,
                ],
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $organizationProduct->fresh(['product']) ?? $organizationProduct;
        });
    }

    public function assertPricingAndComponentsVersions(
        OrganizationProduct $locked,
        int $expectedPricingVersion,
        int $expectedComponentsVersion,
    ): void {
        if ($locked->pricing_version !== $expectedPricingVersion) {
            throw new HttpException(
                409,
                'Pricing was updated by another user. Refresh and review the latest values before saving.'
            );
        }

        if ($locked->components_version !== $expectedComponentsVersion) {
            throw new HttpException(
                409,
                'Component costs changed. Refresh and review the latest estimate before saving.'
            );
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, OrganizationProductComponent>
     */
    public function activeComponentsFor(OrganizationProduct $organizationProduct)
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

    /**
     * @param  Collection<int, OrganizationProductComponent>  $components
     */
    public function estimateMaterialCostMicroUnits(
        OrganizationProduct $finished,
        $components,
    ): int {
        $finished->loadMissing('product');

        try {
            $estimate = $this->componentCostEstimator->estimate(
                $this->componentCostMapper->toEstimateInput($finished, $components),
            );
        } catch (InvalidComponentCostException $exception) {
            throw ValidationException::withMessages([
                'components' => $exception->getMessage(),
            ]);
        }

        return $estimate->totalEstimatedMaterialCostMicroUnits;
    }

    /**
     * @param  array<string, mixed>  $pricing
     */
    private function assertPricingConfiguration(array $pricing): void
    {
        try {
            $input = new PricingInput(
                materialCostMicroUnits: (int) $pricing['material_cost_micro_units'],
                laborCostMicroUnits: (int) $pricing['labor_cost_micro_units'],
                overheadMode: OverheadMode::from((string) $pricing['overhead_mode']),
                overheadAmountMicroUnits: (int) $pricing['overhead_amount_micro_units'],
                overheadRateBasisPoints: (int) $pricing['overhead_rate_basis_points'],
                pricingMethod: PricingMethod::from((string) $pricing['pricing_method']),
                markupBasisPoints: (int) $pricing['markup_basis_points'],
                targetMarginBasisPoints: (int) $pricing['target_margin_basis_points'],
                fixedPriceCents: $pricing['fixed_price_cents'] !== null ? (int) $pricing['fixed_price_cents'] : null,
                minimumPriceCents: $pricing['minimum_price_cents'] !== null ? (int) $pricing['minimum_price_cents'] : null,
                allowPriceOverride: (bool) $pricing['allow_price_override'],
                requestedOverridePriceCents: null,
                quantity: '1',
                currencyCode: PricingCalculator::CURRENCY_USD,
                pricingVersion: 1,
            );

            $result = (new PricingCalculator)->calculate($input);
        } catch (InvalidPricingException $exception) {
            throw ValidationException::withMessages([
                'pricing' => $exception->getMessage(),
            ]);
        }

        if ($result->belowMinimum) {
            throw ValidationException::withMessages([
                'minimum_price' => 'The calculated selling price cannot be below the minimum selling price.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function masterAuditPayload(?Product $product): array
    {
        if ($product === null) {
            return [];
        }

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'product_family' => $product->product_family->value,
            'item_kind' => $product->item_kind->value,
            'unit_of_measure' => $product->unit_of_measure->value,
            'is_active' => $product->is_active,
            'vendor_id' => $product->vendor_id,
            'product_category_id' => $product->product_category_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function organizationProductAuditPayload(?OrganizationProduct $organizationProduct): array
    {
        if ($organizationProduct === null) {
            return [];
        }

        return [
            ...$this->settingsAuditPayload($organizationProduct),
            ...$this->pricingAuditPayload($organizationProduct),
            'product_id' => $organizationProduct->product_id,
            'organization_id' => $organizationProduct->organization_id,
            'purchase_cost_micro_units' => $organizationProduct->purchase_cost_micro_units,
            'components_version' => $organizationProduct->components_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsAuditPayload(?OrganizationProduct $organizationProduct): array
    {
        if ($organizationProduct === null) {
            return [];
        }

        return [
            'display_name' => $organizationProduct->display_name,
            'is_available' => $organizationProduct->is_available,
            'is_sellable' => $organizationProduct->is_sellable,
            'is_purchasable' => $organizationProduct->is_purchasable,
            'inventory_tracking_mode' => $organizationProduct->inventory_tracking_mode->value,
            'purchase_unit_of_measure' => $organizationProduct->purchase_unit_of_measure?->value,
            'stock_unit_of_measure' => $organizationProduct->stock_unit_of_measure?->value,
            'usage_unit_of_measure' => $organizationProduct->usage_unit_of_measure?->value,
            'lead_time_days' => $organizationProduct->lead_time_days,
            'notes' => $organizationProduct->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pricingAuditPayload(?OrganizationProduct $organizationProduct): array
    {
        if ($organizationProduct === null) {
            return [];
        }

        return [
            'pricing_version' => $organizationProduct->pricing_version,
            'components_version' => $organizationProduct->components_version,
            'material_cost_micro_units' => $organizationProduct->material_cost_micro_units,
            'labor_cost_micro_units' => $organizationProduct->labor_cost_micro_units,
            'overhead_mode' => $organizationProduct->overhead_mode->value,
            'overhead_amount_micro_units' => $organizationProduct->overhead_amount_micro_units,
            'overhead_rate_basis_points' => $organizationProduct->overhead_rate_basis_points,
            'pricing_method' => $organizationProduct->pricing_method->value,
            'markup_basis_points' => $organizationProduct->markup_basis_points,
            'target_margin_basis_points' => $organizationProduct->target_margin_basis_points,
            'fixed_price_cents' => $organizationProduct->fixed_price_cents,
            'minimum_price_cents' => $organizationProduct->minimum_price_cents,
            'allow_price_override' => $organizationProduct->allow_price_override,
            'currency_code' => $organizationProduct->currency_code,
        ];
    }
}
