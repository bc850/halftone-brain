<?php

namespace App\Support\Catalog;

use App\Enums\UnitOfMeasure;
use App\Enums\VendorProductOfferingStatus;
use App\Models\OrganizationProduct;
use App\Models\OrganizationProductSource;
use App\Models\ParentAccount;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorProductOffering;
use App\Support\Audit\Auditor;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Parent-scoped vendor offering mutations. Never writes products.vendor_id,
 * organization sources, preferred source, price events, or purchase cost.
 * Blocks structural edits while active sources exist and discontinuation while
 * preferred sources still reference the offering.
 */
final class VendorProductOfferingService
{
    public function __construct(private Auditor $auditor) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(
        TenantContext $tenant,
        User $actor,
        Request $request,
        array $data,
    ): VendorProductOffering {
        return DB::transaction(function () use ($tenant, $actor, $request, $data): VendorProductOffering {
            $product = $this->resolveProduct($tenant, (int) $data['product_id']);
            $originalVendorId = $product->vendor_id;
            $vendor = $this->resolveVendor($tenant, (int) $data['vendor_id']);
            $vendorSku = $this->normalizeVendorSku((string) $data['vendor_sku']);
            $this->assertUniqueVendorSku($tenant, $vendor->id, $vendorSku);

            $offering = VendorProductOffering::query()->create([
                'parent_account_id' => $tenant->parentAccountId,
                'product_id' => $product->id,
                'vendor_id' => $vendor->id,
                'vendor_sku' => $vendorSku,
                'vendor_description' => $this->nullableString($data['vendor_description'] ?? null),
                'manufacturer' => $this->nullableString($data['manufacturer'] ?? null),
                'manufacturer_part_number' => $this->nullableString($data['manufacturer_part_number'] ?? null),
                'product_url' => $this->nullableString($data['product_url'] ?? null),
                'purchase_uom' => UnitOfMeasure::from((string) $data['purchase_uom']),
                'package_quantity_scaled' => $this->quantityToScaled((string) $data['package_quantity']),
                'minimum_order_quantity_scaled' => $this->optionalQuantityToScaled(
                    isset($data['minimum_order_quantity']) ? (string) $data['minimum_order_quantity'] : null,
                ),
                'lead_time_days' => array_key_exists('lead_time_days', $data)
                    ? ($data['lead_time_days'] === null ? null : (int) $data['lead_time_days'])
                    : null,
                'status' => VendorProductOfferingStatus::Active,
            ]);

            $this->assertProductVendorUntouched($product->id, $originalVendorId);

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: 'catalog.vendor_product_offering.created',
                subjectType: VendorProductOffering::class,
                subjectId: $offering->id,
                organization: $tenant->organization,
                actor: $actor,
                after: $this->auditPayload($offering),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $offering->fresh(['product', 'vendor']) ?? $offering;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        TenantContext $tenant,
        User $actor,
        Request $request,
        VendorProductOffering $offering,
        array $data,
    ): VendorProductOffering {
        $this->assertOfferingInTenant($tenant, $offering);

        return DB::transaction(function () use ($tenant, $actor, $request, $offering, $data): VendorProductOffering {
            $locked = VendorProductOffering::query()
                ->whereKey($offering->id)
                ->lockForUpdate()
                ->firstOrFail();

            $product = Product::query()->whereKey($locked->product_id)->firstOrFail();
            $originalVendorId = $product->vendor_id;

            $before = $this->auditPayload($locked);
            $vendorSku = $this->normalizeVendorSku((string) $data['vendor_sku']);
            $this->assertUniqueVendorSku($tenant, $locked->vendor_id, $vendorSku, $locked->id);

            $newPurchaseUom = UnitOfMeasure::from((string) $data['purchase_uom']);
            $newPackageQuantityScaled = $this->quantityToScaled((string) $data['package_quantity']);
            $structuralChange = $locked->purchase_uom !== $newPurchaseUom
                || $locked->package_quantity_scaled !== $newPackageQuantityScaled;

            if ($structuralChange && $this->hasActiveOrganizationSources($locked->id)) {
                throw ValidationException::withMessages([
                    'package_quantity' => 'Package quantity and purchase UOM cannot change while active organization sources use this offering. Create a new offering instead.',
                ]);
            }

            // Preserve status — editing a discontinued offering must not reactivate it.
            $locked->fill([
                'vendor_sku' => $vendorSku,
                'vendor_description' => $this->nullableString($data['vendor_description'] ?? null),
                'manufacturer' => $this->nullableString($data['manufacturer'] ?? null),
                'manufacturer_part_number' => $this->nullableString($data['manufacturer_part_number'] ?? null),
                'product_url' => $this->nullableString($data['product_url'] ?? null),
                'purchase_uom' => $newPurchaseUom,
                'package_quantity_scaled' => $newPackageQuantityScaled,
                'minimum_order_quantity_scaled' => $this->optionalQuantityToScaled(
                    array_key_exists('minimum_order_quantity', $data)
                        ? (($data['minimum_order_quantity'] === null || $data['minimum_order_quantity'] === '')
                            ? null
                            : (string) $data['minimum_order_quantity'])
                        : ($locked->minimum_order_quantity_scaled === null
                            ? null
                            : ComponentCostEstimator::scaledToQuantity($locked->minimum_order_quantity_scaled)),
                ),
                'lead_time_days' => array_key_exists('lead_time_days', $data)
                    ? ($data['lead_time_days'] === null || $data['lead_time_days'] === ''
                        ? null
                        : (int) $data['lead_time_days'])
                    : $locked->lead_time_days,
            ]);
            $locked->save();

            $this->assertProductVendorUntouched($product->id, $originalVendorId);

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: 'catalog.vendor_product_offering.updated',
                subjectType: VendorProductOffering::class,
                subjectId: $locked->id,
                organization: $tenant->organization,
                actor: $actor,
                before: $before,
                after: $this->auditPayload($locked->fresh()),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $locked->fresh(['product', 'vendor']) ?? $locked;
        });
    }

    public function discontinue(
        TenantContext $tenant,
        User $actor,
        Request $request,
        VendorProductOffering $offering,
    ): VendorProductOffering {
        $this->assertOfferingInTenant($tenant, $offering);

        return DB::transaction(function () use ($tenant, $actor, $request, $offering): VendorProductOffering {
            $locked = VendorProductOffering::query()
                ->whereKey($offering->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === VendorProductOfferingStatus::Discontinued) {
                throw ValidationException::withMessages([
                    'status' => 'This offering is already discontinued.',
                ]);
            }

            if ($this->hasPreferredOrganizationSources($locked->id)) {
                throw ValidationException::withMessages([
                    'status' => 'Cannot discontinue this offering while it backs a preferred organization source. Clear or replace those preferred sources first.',
                ]);
            }

            $before = $this->auditPayload($locked);
            $locked->status = VendorProductOfferingStatus::Discontinued;
            $locked->save();

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: 'catalog.vendor_product_offering.discontinued',
                subjectType: VendorProductOffering::class,
                subjectId: $locked->id,
                organization: $tenant->organization,
                actor: $actor,
                before: $before,
                after: $this->auditPayload($locked->fresh()),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $locked->fresh(['product', 'vendor']) ?? $locked;
        });
    }

    public function reactivate(
        TenantContext $tenant,
        User $actor,
        Request $request,
        VendorProductOffering $offering,
    ): VendorProductOffering {
        $this->assertOfferingInTenant($tenant, $offering);

        return DB::transaction(function () use ($tenant, $actor, $request, $offering): VendorProductOffering {
            $locked = VendorProductOffering::query()
                ->whereKey($offering->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === VendorProductOfferingStatus::Active) {
                throw ValidationException::withMessages([
                    'status' => 'This offering is already active.',
                ]);
            }

            $before = $this->auditPayload($locked);
            $locked->status = VendorProductOfferingStatus::Active;
            $locked->save();

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: 'catalog.vendor_product_offering.reactivated',
                subjectType: VendorProductOffering::class,
                subjectId: $locked->id,
                organization: $tenant->organization,
                actor: $actor,
                before: $before,
                after: $this->auditPayload($locked->fresh()),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $locked->fresh(['product', 'vendor']) ?? $locked;
        });
    }

    private function resolveProduct(TenantContext $tenant, int $productId): Product
    {
        $product = Product::query()
            ->whereKey($productId)
            ->where('parent_account_id', $tenant->parentAccountId)
            ->first();

        if ($product === null) {
            throw new HttpException(404, 'Product master not found in this parent account.');
        }

        return $product;
    }

    private function resolveVendor(TenantContext $tenant, int $vendorId): Vendor
    {
        $vendor = Vendor::query()
            ->whereKey($vendorId)
            ->where('parent_account_id', $tenant->parentAccountId)
            ->first();

        if ($vendor === null) {
            throw new HttpException(404, 'Vendor not found in this parent account.');
        }

        return $vendor;
    }

    private function assertOfferingInTenant(TenantContext $tenant, VendorProductOffering $offering): void
    {
        if ($offering->parent_account_id !== $tenant->parentAccountId) {
            throw new HttpException(404, 'Vendor offering not found in this parent account.');
        }
    }

    private function normalizeVendorSku(string $sku): string
    {
        $normalized = trim($sku);

        if ($normalized === '') {
            throw ValidationException::withMessages([
                'vendor_sku' => 'Vendor SKU is required.',
            ]);
        }

        return $normalized;
    }

    private function assertUniqueVendorSku(
        TenantContext $tenant,
        int $vendorId,
        string $vendorSku,
        ?int $ignoreId = null,
    ): void {
        $query = VendorProductOffering::query()
            ->where('parent_account_id', $tenant->parentAccountId)
            ->where('vendor_id', $vendorId)
            ->where('vendor_sku', $vendorSku);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'vendor_sku' => 'This vendor SKU is already used for this vendor.',
            ]);
        }
    }

    private function quantityToScaled(string $quantity): int
    {
        $scaled = ComponentCostEstimator::quantityToScaled($quantity);

        if ($scaled < 1) {
            throw ValidationException::withMessages([
                'package_quantity' => 'Package quantity must be greater than zero.',
            ]);
        }

        return $scaled;
    }

    private function optionalQuantityToScaled(?string $quantity): ?int
    {
        if ($quantity === null || trim($quantity) === '') {
            return null;
        }

        $scaled = ComponentCostEstimator::quantityToScaled(trim($quantity));

        if ($scaled < 1) {
            throw ValidationException::withMessages([
                'minimum_order_quantity' => 'Minimum order quantity must be greater than zero when set.',
            ]);
        }

        return $scaled;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function hasActiveOrganizationSources(int $offeringId): bool
    {
        return OrganizationProductSource::query()
            ->where('vendor_product_offering_id', $offeringId)
            ->where('is_active', true)
            ->exists();
    }

    private function hasPreferredOrganizationSources(int $offeringId): bool
    {
        return OrganizationProduct::query()
            ->whereNotNull('preferred_source_id')
            ->whereHas('preferredSource', function ($query) use ($offeringId): void {
                $query->where('vendor_product_offering_id', $offeringId);
            })
            ->exists();
    }

    private function assertProductVendorUntouched(int $productId, ?int $originalVendorId): void
    {
        $freshVendorId = Product::query()->whereKey($productId)->value('vendor_id');

        if ($freshVendorId !== $originalVendorId) {
            throw new \LogicException('Vendor offering mutations must not write products.vendor_id.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(?VendorProductOffering $offering): array
    {
        if ($offering === null) {
            return [];
        }

        $offering->loadMissing(['product:id,sku', 'vendor:id']);

        return [
            'id' => $offering->id,
            'product_id' => $offering->product_id,
            'product_sku' => $offering->product?->sku,
            'vendor_id' => $offering->vendor_id,
            'vendor_sku' => $offering->vendor_sku,
            'vendor_description' => $offering->vendor_description,
            'manufacturer' => $offering->manufacturer,
            'manufacturer_part_number' => $offering->manufacturer_part_number,
            'product_url' => $offering->product_url,
            'purchase_uom' => $offering->purchase_uom->value,
            'package_quantity_scaled' => $offering->package_quantity_scaled,
            'minimum_order_quantity_scaled' => $offering->minimum_order_quantity_scaled,
            'lead_time_days' => $offering->lead_time_days,
            'status' => $offering->status->value,
            'discontinued_at' => $offering->discontinued_at?->toIso8601String(),
        ];
    }
}
