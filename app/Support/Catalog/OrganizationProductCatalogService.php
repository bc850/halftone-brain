<?php

namespace App\Support\Catalog;

use App\Enums\OverheadMode;
use App\Enums\PricingMethod;
use App\Models\OrganizationProduct;
use App\Models\ParentAccount;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Pricing\PricingCalculator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Transactional organization catalog mutations with audited pricing-version control.
 */
final class OrganizationProductCatalogService
{
    public function __construct(private Auditor $auditor) {}

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

        return DB::transaction(function () use ($tenant, $actor, $request, $organizationProduct, $product, $masterData, $before): OrganizationProduct {
            $product->update([
                'name' => $masterData['name'],
                'product_family' => $masterData['product_family'],
                'sku' => $masterData['sku'],
                'vendor_sku' => $masterData['vendor_sku'] ?? null,
                'vendor_id' => $masterData['vendor_id'] ?? null,
                'product_category_id' => $masterData['product_category_id'] ?? null,
                'unit_of_measure' => $masterData['unit_of_measure'],
                'description' => $masterData['description'] ?? null,
                'notes' => $masterData['notes'] ?? null,
                'is_active' => (bool) ($masterData['is_active'] ?? $product->is_active),
            ]);

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: 'catalog.product_master.updated',
                subjectType: Product::class,
                subjectId: $product->id,
                organization: $tenant->organization,
                actor: $actor,
                before: $before,
                after: $this->masterAuditPayload($product->fresh()),
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
            $organizationProduct->update([
                'display_name' => $settings['display_name'] ?? null,
                'is_available' => (bool) ($settings['is_available'] ?? $organizationProduct->is_available),
                'lead_time_days' => $settings['lead_time_days'] ?? null,
                'notes' => $settings['notes'] ?? null,
            ]);

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: 'catalog.organization_product.settings_updated',
                subjectType: OrganizationProduct::class,
                subjectId: $organizationProduct->id,
                organization: $tenant->organization,
                actor: $actor,
                before: $before,
                after: $this->settingsAuditPayload($organizationProduct->fresh()),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $organizationProduct->fresh(['product']) ?? $organizationProduct;
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
        int $expectedVersion,
    ): OrganizationProduct {
        return DB::transaction(function () use ($tenant, $actor, $request, $organizationProduct, $pricing, $expectedVersion): OrganizationProduct {
            $locked = OrganizationProduct::query()
                ->whereKey($organizationProduct->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->pricing_version !== $expectedVersion) {
                throw new HttpException(
                    409,
                    'Pricing was updated by another user. Refresh and review the latest values before saving.'
                );
            }

            $before = $this->pricingAuditPayload($locked);

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

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: 'catalog.organization_product.pricing_updated',
                subjectType: OrganizationProduct::class,
                subjectId: $locked->id,
                organization: $tenant->organization,
                actor: $actor,
                before: $before,
                after: $this->pricingAuditPayload($locked),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $locked->load('product');
        });
    }

    public function archive(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProduct $organizationProduct,
    ): OrganizationProduct {
        $before = ['is_available' => $organizationProduct->is_available];

        return DB::transaction(function () use ($tenant, $actor, $request, $organizationProduct, $before): OrganizationProduct {
            $organizationProduct->update(['is_available' => false]);

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: 'catalog.organization_product.archived',
                subjectType: OrganizationProduct::class,
                subjectId: $organizationProduct->id,
                organization: $tenant->organization,
                actor: $actor,
                before: $before,
                after: ['is_available' => false, 'pricing_version' => $organizationProduct->pricing_version],
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $organizationProduct->fresh(['product']) ?? $organizationProduct;
        });
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
