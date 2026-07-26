<?php

namespace App\Support\Catalog;

use App\Enums\VendorProductOfferingStatus;
use App\Models\OrganizationProduct;
use App\Models\OrganizationProductSource;
use App\Models\OrganizationProductSourcePriceEvent;
use App\Models\OrganizationProductUnitConversion;
use App\Models\ParentAccount;
use App\Models\User;
use App\Models\VendorProductOffering;
use App\Support\Audit\Auditor;
use App\Support\Catalog\ComponentCost\ComponentConversionInput;
use App\Support\Catalog\ComponentCost\ComponentDependencyVersionService;
use App\Support\Catalog\ComponentCost\InvalidComponentCostException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Organization-scoped vendor source mutations.
 *
 * Lock order (ascending ids within each tier):
 * 1. organization_products
 * 2. organization_product_sources
 * 3. vendor_product_offerings
 * 4. dependent finished organization_products (via ComponentDependencyVersionService)
 *
 * Never writes products.vendor_id, inventory, purchase orders, or QuickBooks.
 */
final class OrganizationProductSourceService
{
    public function __construct(
        private Auditor $auditor,
        private VendorPackagePriceNormalizer $normalizer,
        private ComponentDependencyVersionService $componentVersions,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function attach(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProduct $organizationProduct,
        array $data,
    ): OrganizationProductSource {
        $this->assertOrganizationProductInTenant($tenant, $organizationProduct);

        return DB::transaction(function () use ($tenant, $actor, $request, $organizationProduct, $data): OrganizationProductSource {
            $op = $this->lockOrganizationProduct($organizationProduct->id);
            $this->assertOrganizationProductInTenant($tenant, $op);

            $offering = $this->lockOffering((int) $data['vendor_product_offering_id']);
            $this->assertOfferingAttachable($tenant, $op, $offering);

            if (
                OrganizationProductSource::query()
                    ->where('organization_product_id', $op->id)
                    ->where('vendor_product_offering_id', $offering->id)
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'vendor_product_offering_id' => 'This vendor offering is already attached as a source.',
                ]);
            }

            $packagePrice = array_key_exists('current_package_price_micro_units', $data)
                ? $data['current_package_price_micro_units']
                : null;
            $effective = null;

            if ($packagePrice !== null) {
                if (! is_int($packagePrice)) {
                    throw ValidationException::withMessages([
                        'package_price' => 'Package price must be an integer micro-unit amount.',
                    ]);
                }

                $effective = $this->normalizeOrFail($op, $offering, $packagePrice);
            }

            $source = OrganizationProductSource::query()->create([
                'parent_account_id' => $tenant->parentAccountId,
                'organization_id' => $tenant->organizationId,
                'organization_product_id' => $op->id,
                'vendor_product_offering_id' => $offering->id,
                'current_package_price_micro_units' => $packagePrice,
                'currency_code' => $op->currency_code,
                'price_version' => 1,
                'is_active' => true,
            ]);

            if ($effective !== null) {
                $this->appendPriceEvent(
                    $source,
                    $actor,
                    (int) $packagePrice,
                    $effective,
                    isset($data['note']) ? (is_string($data['note']) ? $data['note'] : null) : null,
                );
            }

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: 'catalog.organization_product_source.attached',
                subjectType: OrganizationProductSource::class,
                subjectId: $source->id,
                organization: $tenant->organization,
                actor: $actor,
                after: $this->auditPayload($source->fresh(['vendorProductOffering'])),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $source->fresh(['vendorProductOffering.vendor', 'vendorProductOffering.product']) ?? $source;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePackagePrice(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProductSource $source,
        array $data,
    ): OrganizationProductSource {
        $this->assertSourceInTenant($tenant, $source);

        return DB::transaction(function () use ($tenant, $actor, $request, $source, $data): OrganizationProductSource {
            $op = $this->lockOrganizationProduct($source->organization_product_id);
            $locked = $this->lockSource($source->id);
            $offering = $this->lockOffering($locked->vendor_product_offering_id);

            $this->assertSourceInTenant($tenant, $locked);
            $this->assertOrganizationProductInTenant($tenant, $op);

            if ((int) $data['expected_price_version'] !== $locked->price_version) {
                throw new HttpException(409, 'Source price version is stale.');
            }

            $newPrice = (int) $data['current_package_price_micro_units'];
            if ($newPrice < 0) {
                throw ValidationException::withMessages([
                    'package_price' => 'Package price cannot be negative.',
                ]);
            }

            if ($locked->currency_code !== $op->currency_code) {
                throw ValidationException::withMessages([
                    'package_price' => 'Source currency must match the organization product currency.',
                ]);
            }

            // Idempotent unchanged submission: no version bump, event, or OP mutation.
            if ($locked->current_package_price_micro_units === $newPrice) {
                return $locked->fresh(['vendorProductOffering.vendor', 'vendorProductOffering.product']) ?? $locked;
            }

            $effective = $this->normalizeOrFail($op, $offering, $newPrice);
            $before = $this->auditPayload($locked);
            $previousEffective = $op->purchase_cost_micro_units;
            $isPreferred = $op->preferred_source_id === $locked->id;

            $locked->forceFill([
                'current_package_price_micro_units' => $newPrice,
                'price_version' => $locked->price_version + 1,
            ])->save();

            $this->appendPriceEvent(
                $locked,
                $actor,
                $newPrice,
                $effective,
                $data['note'] ?? null,
            );

            $invalidation = [
                'invalidated_count' => 0,
                'correlation_id' => null,
                'finished_ids' => [],
            ];

            if ($isPreferred) {
                $op->forceFill(['purchase_cost_micro_units' => $effective])->save();

                if ($previousEffective !== $effective) {
                    $invalidation = $this->componentVersions->invalidateDependentsOfMaterial($op);
                }
            }

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: 'catalog.organization_product_source.price_changed',
                subjectType: OrganizationProductSource::class,
                subjectId: $locked->id,
                organization: $tenant->organization,
                actor: $actor,
                before: $before,
                after: array_merge($this->auditPayload($locked->fresh()), [
                    'effective_purchase_unit_cost_micro_units' => $effective,
                    'is_preferred' => $isPreferred,
                    'organization_product_purchase_cost_micro_units' => $op->fresh()?->purchase_cost_micro_units,
                    'pricing_version' => $op->fresh()?->pricing_version,
                    'components_version' => $op->fresh()?->components_version,
                    'dependents_invalidated' => $invalidation['invalidated_count'] > 0,
                    'affected_dependent_count' => $invalidation['invalidated_count'],
                ]),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
                correlationId: $invalidation['correlation_id'],
            );

            return $locked->fresh(['vendorProductOffering.vendor', 'vendorProductOffering.product']) ?? $locked;
        });
    }

    public function activate(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProductSource $source,
    ): OrganizationProductSource {
        return $this->setActive($tenant, $actor, $request, $source, true);
    }

    public function deactivate(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProductSource $source,
    ): OrganizationProductSource {
        return $this->setActive($tenant, $actor, $request, $source, false);
    }

    /**
     * @param  array{expected_preferred_source_id?: int|null}  $data
     */
    public function selectPreferred(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProduct $organizationProduct,
        OrganizationProductSource $source,
        array $data = [],
    ): OrganizationProduct {
        $this->assertOrganizationProductInTenant($tenant, $organizationProduct);
        $this->assertSourceInTenant($tenant, $source);

        if ($source->organization_product_id !== $organizationProduct->id) {
            abort(404);
        }

        return DB::transaction(function () use ($tenant, $actor, $request, $organizationProduct, $source, $data): OrganizationProduct {
            $op = $this->lockOrganizationProduct($organizationProduct->id);
            $lockedSource = $this->lockSource($source->id);
            $offering = $this->lockOffering($lockedSource->vendor_product_offering_id);

            $this->assertOrganizationProductInTenant($tenant, $op);
            $this->assertSourceInTenant($tenant, $lockedSource);

            if (array_key_exists('expected_preferred_source_id', $data)) {
                $expected = $data['expected_preferred_source_id'];
                if ($expected !== $op->preferred_source_id) {
                    throw new HttpException(409, 'Preferred source expectation is stale.');
                }
            }

            $this->assertPreferredEligible($op, $lockedSource, $offering);

            $packagePrice = $lockedSource->current_package_price_micro_units;
            if ($packagePrice === null) {
                throw ValidationException::withMessages([
                    'preferred_source_id' => 'Preferred source must have a current package price.',
                ]);
            }

            $effective = $this->normalizeOrFail($op, $offering, $packagePrice);
            $previousPreferred = $op->preferred_source_id;
            $previousCost = $op->purchase_cost_micro_units;
            $alreadyPreferred = $previousPreferred === $lockedSource->id;
            $costAlreadySynced = $previousCost === $effective;

            if ($alreadyPreferred && $costAlreadySynced) {
                return $op->fresh(['product', 'preferredSource']) ?? $op;
            }

            $before = [
                'preferred_source_id' => $previousPreferred,
                'purchase_cost_micro_units' => $previousCost,
            ];

            $op->forceFill([
                'preferred_source_id' => $lockedSource->id,
                'purchase_cost_micro_units' => $effective,
            ])->save();

            $invalidation = [
                'invalidated_count' => 0,
                'correlation_id' => null,
                'finished_ids' => [],
            ];

            if ($previousCost !== $effective) {
                $invalidation = $this->componentVersions->invalidateDependentsOfMaterial($op);
            }

            $action = $previousPreferred === null
                ? 'catalog.organization_product.preferred_source_selected'
                : ($alreadyPreferred
                    ? 'catalog.organization_product.preferred_source_selected'
                    : 'catalog.organization_product.preferred_source_changed');

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: $action,
                subjectType: OrganizationProduct::class,
                subjectId: $op->id,
                organization: $tenant->organization,
                actor: $actor,
                before: $before,
                after: [
                    'preferred_source_id' => $op->preferred_source_id,
                    'purchase_cost_micro_units' => $op->purchase_cost_micro_units,
                    'source_id' => $lockedSource->id,
                    'offering_id' => $offering->id,
                    'vendor_id' => $offering->vendor_id,
                    'package_price_micro_units' => $packagePrice,
                    'effective_purchase_unit_cost_micro_units' => $effective,
                    'source_price_version' => $lockedSource->price_version,
                    'pricing_version' => $op->pricing_version,
                    'components_version' => $op->components_version,
                    'dependents_invalidated' => $invalidation['invalidated_count'] > 0,
                    'affected_dependent_count' => $invalidation['invalidated_count'],
                ],
                ip: $request->ip(),
                userAgent: $request->userAgent(),
                correlationId: $invalidation['correlation_id'],
            );

            return $op->fresh(['product', 'preferredSource']) ?? $op;
        });
    }

    /**
     * @param  array{expected_preferred_source_id?: int|null}  $data
     */
    public function clearPreferred(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProduct $organizationProduct,
        array $data = [],
    ): OrganizationProduct {
        $this->assertOrganizationProductInTenant($tenant, $organizationProduct);

        return DB::transaction(function () use ($tenant, $actor, $request, $organizationProduct, $data): OrganizationProduct {
            $op = $this->lockOrganizationProduct($organizationProduct->id);
            $this->assertOrganizationProductInTenant($tenant, $op);

            if (array_key_exists('expected_preferred_source_id', $data)) {
                if ($data['expected_preferred_source_id'] !== $op->preferred_source_id) {
                    throw new HttpException(409, 'Preferred source expectation is stale.');
                }
            }

            if ($op->preferred_source_id === null) {
                return $op->fresh(['product', 'preferredSource']) ?? $op;
            }

            $before = [
                'preferred_source_id' => $op->preferred_source_id,
                'purchase_cost_micro_units' => $op->purchase_cost_micro_units,
            ];

            $op->forceFill(['preferred_source_id' => null])->save();

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: 'catalog.organization_product.preferred_source_cleared',
                subjectType: OrganizationProduct::class,
                subjectId: $op->id,
                organization: $tenant->organization,
                actor: $actor,
                before: $before,
                after: [
                    'preferred_source_id' => null,
                    'purchase_cost_micro_units' => $op->purchase_cost_micro_units,
                    'pricing_version' => $op->pricing_version,
                    'components_version' => $op->components_version,
                    'dependents_invalidated' => false,
                    'affected_dependent_count' => 0,
                ],
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $op->fresh(['product', 'preferredSource']) ?? $op;
        });
    }

    private function setActive(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProductSource $source,
        bool $active,
    ): OrganizationProductSource {
        $this->assertSourceInTenant($tenant, $source);

        return DB::transaction(function () use ($tenant, $actor, $request, $source, $active): OrganizationProductSource {
            $op = $this->lockOrganizationProduct($source->organization_product_id);
            $locked = $this->lockSource($source->id);
            $this->assertSourceInTenant($tenant, $locked);

            if (! $active && $op->preferred_source_id === $locked->id) {
                throw ValidationException::withMessages([
                    'is_active' => 'Clear or replace the preferred source before deactivating it.',
                ]);
            }

            if ($locked->is_active === $active) {
                return $locked->fresh(['vendorProductOffering.vendor']) ?? $locked;
            }

            if ($active) {
                $offering = $this->lockOffering($locked->vendor_product_offering_id);
                if ($offering->status !== VendorProductOfferingStatus::Active) {
                    throw ValidationException::withMessages([
                        'is_active' => 'Cannot reactivate a source whose vendor offering is discontinued.',
                    ]);
                }
            }

            $before = $this->auditPayload($locked);
            $locked->forceFill(['is_active' => $active])->save();

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: $active
                    ? 'catalog.organization_product_source.activated'
                    : 'catalog.organization_product_source.deactivated',
                subjectType: OrganizationProductSource::class,
                subjectId: $locked->id,
                organization: $tenant->organization,
                actor: $actor,
                before: $before,
                after: $this->auditPayload($locked->fresh()),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $locked->fresh(['vendorProductOffering.vendor']) ?? $locked;
        });
    }

    private function normalizeOrFail(
        OrganizationProduct $organizationProduct,
        VendorProductOffering $offering,
        int $packagePriceMicroUnits,
    ): int {
        if (! $organizationProduct->is_purchasable) {
            throw ValidationException::withMessages([
                'package_price' => 'Organization product must be purchasable to normalize package pricing.',
            ]);
        }

        if ($organizationProduct->purchase_unit_of_measure === null) {
            throw ValidationException::withMessages([
                'package_price' => 'Purchase unit of measure is required before accepting a package price.',
            ]);
        }

        $organizationProduct->loadMissing('unitConversions');

        try {
            return $this->normalizer->normalize(
                packagePriceMicroUnits: $packagePriceMicroUnits,
                packageQuantityScaled: $offering->package_quantity_scaled,
                offeringPurchaseUom: $offering->purchase_uom,
                organizationPurchaseUom: $organizationProduct->purchase_unit_of_measure,
                conversions: $this->conversionInputs($organizationProduct),
            );
        } catch (InvalidComponentCostException $exception) {
            throw ValidationException::withMessages([
                'package_price' => $exception->getMessage(),
            ]);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'package_price' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array<int, ComponentConversionInput>
     */
    private function conversionInputs(OrganizationProduct $organizationProduct): array
    {
        return $organizationProduct->unitConversions
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

    private function appendPriceEvent(
        OrganizationProductSource $source,
        User $actor,
        int $packagePriceMicroUnits,
        int $effectivePurchaseUnitCostMicroUnits,
        ?string $note,
    ): void {
        OrganizationProductSourcePriceEvent::query()->create([
            'parent_account_id' => $source->parent_account_id,
            'organization_id' => $source->organization_id,
            'organization_product_source_id' => $source->id,
            'package_price_micro_units' => $packagePriceMicroUnits,
            'effective_purchase_unit_cost_micro_units' => $effectivePurchaseUnitCostMicroUnits,
            'currency_code' => $source->currency_code,
            'actor_user_id' => $actor->id,
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'recorded_at' => now(),
        ]);
    }

    private function assertPreferredEligible(
        OrganizationProduct $organizationProduct,
        OrganizationProductSource $source,
        VendorProductOffering $offering,
    ): void {
        if (! $source->is_active) {
            throw ValidationException::withMessages([
                'preferred_source_id' => 'Inactive sources cannot be selected as preferred.',
            ]);
        }

        if ($offering->status !== VendorProductOfferingStatus::Active) {
            throw ValidationException::withMessages([
                'preferred_source_id' => 'Discontinued offerings cannot be selected as preferred.',
            ]);
        }

        if (! $organizationProduct->is_purchasable) {
            throw ValidationException::withMessages([
                'preferred_source_id' => 'Organization product must be purchasable to select a preferred source.',
            ]);
        }

        if ($organizationProduct->purchase_unit_of_measure === null) {
            throw ValidationException::withMessages([
                'preferred_source_id' => 'Purchase unit of measure is required to select a preferred source.',
            ]);
        }
    }

    private function assertOfferingAttachable(
        TenantContext $tenant,
        OrganizationProduct $organizationProduct,
        VendorProductOffering $offering,
    ): void {
        if ($offering->parent_account_id !== $tenant->parentAccountId) {
            abort(404);
        }

        if ($offering->product_id !== $organizationProduct->product_id) {
            throw ValidationException::withMessages([
                'vendor_product_offering_id' => 'Offering must belong to the same product master.',
            ]);
        }

        if ($offering->status !== VendorProductOfferingStatus::Active) {
            throw ValidationException::withMessages([
                'vendor_product_offering_id' => 'Discontinued offerings cannot be attached as new sources.',
            ]);
        }
    }

    private function lockOrganizationProduct(int $id): OrganizationProduct
    {
        return OrganizationProduct::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function lockSource(int $id): OrganizationProductSource
    {
        return OrganizationProductSource::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function lockOffering(int $id): VendorProductOffering
    {
        return VendorProductOffering::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function assertOrganizationProductInTenant(TenantContext $tenant, OrganizationProduct $organizationProduct): void
    {
        if (
            $organizationProduct->organization_id !== $tenant->organizationId
            || $organizationProduct->parent_account_id !== $tenant->parentAccountId
        ) {
            abort(404);
        }
    }

    private function assertSourceInTenant(TenantContext $tenant, OrganizationProductSource $source): void
    {
        if (
            $source->organization_id !== $tenant->organizationId
            || $source->parent_account_id !== $tenant->parentAccountId
        ) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(?OrganizationProductSource $source): array
    {
        if ($source === null) {
            return [];
        }

        $source->loadMissing('vendorProductOffering');

        return [
            'id' => $source->id,
            'organization_product_id' => $source->organization_product_id,
            'vendor_product_offering_id' => $source->vendor_product_offering_id,
            'vendor_id' => $source->vendorProductOffering?->vendor_id,
            'vendor_sku' => $source->vendorProductOffering?->vendor_sku,
            'current_package_price_micro_units' => $source->current_package_price_micro_units,
            'currency_code' => $source->currency_code,
            'price_version' => $source->price_version,
            'is_active' => $source->is_active,
        ];
    }
}
