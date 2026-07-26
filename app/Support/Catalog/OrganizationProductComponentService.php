<?php

namespace App\Support\Catalog;

use App\Enums\ItemKind;
use App\Enums\UnitOfMeasure;
use App\Models\OrganizationProduct;
use App\Models\OrganizationProductComponent;
use App\Models\ParentAccount;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Catalog\ComponentCost\ComponentCostMapper;
use App\Support\Catalog\ComponentCost\ComponentDependencyVersionService;
use App\Support\Catalog\ComponentCost\InvalidComponentCostException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Soft-lifecycle estimated material components for finished organization products.
 */
final class OrganizationProductComponentService
{
    public function __construct(
        private Auditor $auditor,
        private ComponentDependencyVersionService $versions,
        private ComponentCostMapper $mapper,
        private ComponentCostEstimator $estimator,
    ) {}

    /**
     * @param  array{
     *     component_organization_product_id: int,
     *     quantity: string,
     *     usage_uom: string,
     *     waste_basis_points?: int,
     *     sort_order?: int,
     *     components_version: int
     * }  $data
     */
    public function create(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProduct $parent,
        array $data,
    ): OrganizationProductComponent {
        $this->assertTenantOwnsProduct($tenant, $parent);

        return DB::transaction(function () use ($tenant, $actor, $request, $parent, $data): OrganizationProductComponent {
            $locked = $this->lockParentForComponentsVersion($parent, (int) $data['components_version']);
            $this->assertParentEligible($locked);

            $material = $this->resolveMaterial($tenant, (int) $data['component_organization_product_id']);
            $usageUom = UnitOfMeasure::from((string) $data['usage_uom']);
            $quantityScaled = $this->quantityToScaled((string) $data['quantity']);
            $waste = (int) ($data['waste_basis_points'] ?? 0);
            $sortOrder = (int) ($data['sort_order'] ?? 0);

            $this->assertMaterialEligible($material, $usageUom);
            $this->assertUniquePair($locked, $material);

            $component = OrganizationProductComponent::query()->create([
                'parent_account_id' => $tenant->parentAccountId,
                'organization_id' => $tenant->organizationId,
                'organization_product_id' => $locked->id,
                'component_organization_product_id' => $material->id,
                'quantity_scaled' => $quantityScaled,
                'usage_uom' => $usageUom,
                'waste_basis_points' => $waste,
                'sort_order' => $sortOrder,
                'is_active' => true,
            ]);

            $this->assertEstimateable($locked, $component);

            $this->versions->bumpFinishedParent($locked);

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: 'catalog.organization_product_component.created',
                subjectType: OrganizationProductComponent::class,
                subjectId: $component->id,
                organization: $tenant->organization,
                actor: $actor,
                after: $this->auditPayload($component),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $component->fresh(['componentOrganizationProduct.product']) ?? $component;
        });
    }

    /**
     * @param  array{
     *     quantity: string,
     *     usage_uom: string,
     *     waste_basis_points?: int,
     *     sort_order?: int,
     *     components_version: int
     * }  $data
     */
    public function update(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProduct $parent,
        OrganizationProductComponent $component,
        array $data,
    ): OrganizationProductComponent {
        $this->assertTenantOwnsProduct($tenant, $parent);
        $this->assertComponentBelongsToParent($parent, $component);

        return DB::transaction(function () use ($tenant, $actor, $request, $parent, $component, $data): OrganizationProductComponent {
            $locked = $this->lockParentForComponentsVersion($parent, (int) $data['components_version']);
            $this->assertParentEligible($locked);

            $material = $component->componentOrganizationProduct()->with(['product', 'unitConversions'])->firstOrFail();
            $this->assertTenantOwnsProduct($tenant, $material);

            $usageUom = UnitOfMeasure::from((string) $data['usage_uom']);
            $quantityScaled = $this->quantityToScaled((string) $data['quantity']);
            $waste = (int) ($data['waste_basis_points'] ?? $component->waste_basis_points);
            $sortOrder = array_key_exists('sort_order', $data)
                ? (int) $data['sort_order']
                : $component->sort_order;

            if ($component->is_active) {
                $this->assertMaterialEligible($material, $usageUom);
            }

            $before = $this->auditPayload($component);

            $component->fill([
                'quantity_scaled' => $quantityScaled,
                'usage_uom' => $usageUom,
                'waste_basis_points' => $waste,
                'sort_order' => $sortOrder,
            ]);
            $component->save();

            if ($component->is_active) {
                $this->assertEstimateable($locked, $component);
            }

            $this->versions->bumpFinishedParent($locked);

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: 'catalog.organization_product_component.updated',
                subjectType: OrganizationProductComponent::class,
                subjectId: $component->id,
                organization: $tenant->organization,
                actor: $actor,
                before: $before,
                after: $this->auditPayload($component->fresh()),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $component->fresh(['componentOrganizationProduct.product']) ?? $component;
        });
    }

    public function deactivate(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProduct $parent,
        OrganizationProductComponent $component,
        int $expectedComponentsVersion,
    ): OrganizationProductComponent {
        return $this->setActive(
            $tenant,
            $actor,
            $request,
            $parent,
            $component,
            false,
            $expectedComponentsVersion,
            'catalog.organization_product_component.deactivated',
        );
    }

    public function reactivate(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProduct $parent,
        OrganizationProductComponent $component,
        int $expectedComponentsVersion,
    ): OrganizationProductComponent {
        return $this->setActive(
            $tenant,
            $actor,
            $request,
            $parent,
            $component,
            true,
            $expectedComponentsVersion,
            'catalog.organization_product_component.reactivated',
        );
    }

    private function setActive(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProduct $parent,
        OrganizationProductComponent $component,
        bool $active,
        int $expectedComponentsVersion,
        string $action,
    ): OrganizationProductComponent {
        $this->assertTenantOwnsProduct($tenant, $parent);
        $this->assertComponentBelongsToParent($parent, $component);

        return DB::transaction(function () use (
            $tenant,
            $actor,
            $request,
            $parent,
            $component,
            $active,
            $expectedComponentsVersion,
            $action,
        ): OrganizationProductComponent {
            $locked = $this->lockParentForComponentsVersion($parent, $expectedComponentsVersion);
            $this->assertParentEligible($locked);

            if ($active) {
                $material = $component->componentOrganizationProduct()->with(['product', 'unitConversions'])->firstOrFail();
                $this->assertMaterialEligible($material, $component->usage_uom);
            }

            $before = $this->auditPayload($component);
            $component->update(['is_active' => $active]);

            if ($active) {
                $this->assertEstimateable($locked, $component->fresh() ?? $component);
            }

            $this->versions->bumpFinishedParent($locked);

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: $action,
                subjectType: OrganizationProductComponent::class,
                subjectId: $component->id,
                organization: $tenant->organization,
                actor: $actor,
                before: $before,
                after: $this->auditPayload($component->fresh()),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $component->fresh(['componentOrganizationProduct.product']) ?? $component;
        });
    }

    private function lockParentForComponentsVersion(OrganizationProduct $parent, int $expectedVersion): OrganizationProduct
    {
        $locked = OrganizationProduct::query()
            ->whereKey($parent->id)
            ->lockForUpdate()
            ->firstOrFail();

        $locked->loadMissing('product');

        if ($locked->components_version !== $expectedVersion) {
            throw new HttpException(
                409,
                'Component list was updated by another user. Refresh and try again.'
            );
        }

        return $locked;
    }

    private function assertParentEligible(OrganizationProduct $parent): void
    {
        $parent->loadMissing('product');

        if (! in_array($parent->product->item_kind, [ItemKind::Product, ItemKind::Service], true)) {
            throw ValidationException::withMessages([
                'organization_product_id' => 'Only product or service items may have estimated components.',
            ]);
        }

        if (! $parent->is_sellable) {
            throw ValidationException::withMessages([
                'organization_product_id' => 'Finished item must be sellable to manage components.',
            ]);
        }
    }

    private function resolveMaterial(TenantContext $tenant, int $materialId): OrganizationProduct
    {
        $material = OrganizationProduct::query()
            ->with(['product', 'unitConversions'])
            ->whereKey($materialId)
            ->first();

        if (
            $material === null
            || $material->organization_id !== $tenant->organizationId
            || $material->parent_account_id !== $tenant->parentAccountId
        ) {
            abort(404);
        }

        return $material;
    }

    private function assertMaterialEligible(OrganizationProduct $material, UnitOfMeasure $usageUom): void
    {
        $reason = $this->mapper->materialIneligibilityReason($material, $usageUom);

        if ($reason !== null) {
            throw ValidationException::withMessages([
                'component_organization_product_id' => $reason,
            ]);
        }
    }

    private function assertUniquePair(OrganizationProduct $parent, OrganizationProduct $material): void
    {
        $exists = OrganizationProductComponent::query()
            ->where('organization_product_id', $parent->id)
            ->where('component_organization_product_id', $material->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'component_organization_product_id' => 'This material is already a component of this product. Edit or reactivate the existing row.',
            ]);
        }
    }

    private function assertEstimateable(OrganizationProduct $parent, OrganizationProductComponent $component): void
    {
        $parent->loadMissing(['product']);
        $active = OrganizationProductComponent::query()
            ->with(['componentOrganizationProduct.product', 'componentOrganizationProduct.unitConversions'])
            ->where('organization_product_id', $parent->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        try {
            $this->estimator->estimate($this->mapper->toEstimateInput($parent, $active));
        } catch (InvalidComponentCostException $exception) {
            throw ValidationException::withMessages([
                'components' => $exception->getMessage(),
            ]);
        }
    }

    private function quantityToScaled(string $quantity): int
    {
        try {
            return ComponentCostEstimator::quantityToScaled($quantity);
        } catch (InvalidComponentCostException $exception) {
            throw ValidationException::withMessages([
                'quantity' => $exception->getMessage(),
            ]);
        }
    }

    private function assertTenantOwnsProduct(TenantContext $tenant, OrganizationProduct $organizationProduct): void
    {
        if (
            $organizationProduct->organization_id !== $tenant->organizationId
            || $organizationProduct->parent_account_id !== $tenant->parentAccountId
        ) {
            abort(404);
        }
    }

    private function assertComponentBelongsToParent(
        OrganizationProduct $parent,
        OrganizationProductComponent $component,
    ): void {
        if (
            $component->organization_product_id !== $parent->id
            || $component->organization_id !== $parent->organization_id
            || $component->parent_account_id !== $parent->parent_account_id
        ) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(?OrganizationProductComponent $component): array
    {
        if ($component === null) {
            return [];
        }

        return [
            'id' => $component->id,
            'organization_product_id' => $component->organization_product_id,
            'component_organization_product_id' => $component->component_organization_product_id,
            'quantity_scaled' => $component->quantity_scaled,
            'usage_uom' => $component->usage_uom->value,
            'waste_basis_points' => $component->waste_basis_points,
            'sort_order' => $component->sort_order,
            'is_active' => $component->is_active,
        ];
    }
}
