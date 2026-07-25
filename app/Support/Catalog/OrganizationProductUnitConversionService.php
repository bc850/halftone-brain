<?php

namespace App\Support\Catalog;

use App\Models\OrganizationProduct;
use App\Models\OrganizationProductUnitConversion;
use App\Models\ParentAccount;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Organization-scoped exact unit conversion mutations.
 * Does not create reciprocal rows or inventory transactions.
 */
final class OrganizationProductUnitConversionService
{
    public function __construct(private Auditor $auditor) {}

    /**
     * @param  array{from_unit: string, to_unit: string, numerator: int, denominator: int, is_active?: bool}  $data
     */
    public function create(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProduct $organizationProduct,
        array $data,
    ): OrganizationProductUnitConversion {
        $this->assertTenantOwnsProduct($tenant, $organizationProduct);
        $this->assertDistinctUnits($data['from_unit'], $data['to_unit']);
        $this->assertPositiveRatio((int) $data['numerator'], (int) $data['denominator']);

        $duplicate = OrganizationProductUnitConversion::query()
            ->where('organization_product_id', $organizationProduct->id)
            ->where('from_unit', $data['from_unit'])
            ->where('to_unit', $data['to_unit'])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'from_unit' => 'A conversion already exists for this from/to unit pair. Edit or reactivate the existing conversion.',
            ]);
        }

        return DB::transaction(function () use ($tenant, $actor, $request, $organizationProduct, $data): OrganizationProductUnitConversion {
            $conversion = OrganizationProductUnitConversion::query()->create([
                'parent_account_id' => $tenant->parentAccountId,
                'organization_id' => $tenant->organizationId,
                'organization_product_id' => $organizationProduct->id,
                'from_unit' => $data['from_unit'],
                'to_unit' => $data['to_unit'],
                'numerator' => (int) $data['numerator'],
                'denominator' => (int) $data['denominator'],
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: 'catalog.organization_product_unit_conversion.created',
                subjectType: OrganizationProductUnitConversion::class,
                subjectId: $conversion->id,
                organization: $tenant->organization,
                actor: $actor,
                after: $this->auditPayload($conversion),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $conversion;
        });
    }

    /**
     * @param  array{from_unit: string, to_unit: string, numerator: int, denominator: int, is_active?: bool}  $data
     */
    public function update(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProduct $organizationProduct,
        OrganizationProductUnitConversion $conversion,
        array $data,
    ): OrganizationProductUnitConversion {
        $this->assertTenantOwnsProduct($tenant, $organizationProduct);
        $this->assertConversionBelongsToProduct($organizationProduct, $conversion);
        $this->assertDistinctUnits($data['from_unit'], $data['to_unit']);
        $this->assertPositiveRatio((int) $data['numerator'], (int) $data['denominator']);

        $duplicate = OrganizationProductUnitConversion::query()
            ->where('organization_product_id', $organizationProduct->id)
            ->where('from_unit', $data['from_unit'])
            ->where('to_unit', $data['to_unit'])
            ->whereKeyNot($conversion->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'from_unit' => 'A conversion already exists for this from/to unit pair.',
            ]);
        }

        return DB::transaction(function () use ($tenant, $actor, $request, $conversion, $data): OrganizationProductUnitConversion {
            $before = $this->auditPayload($conversion);

            $conversion->fill([
                'from_unit' => $data['from_unit'],
                'to_unit' => $data['to_unit'],
                'numerator' => (int) $data['numerator'],
                'denominator' => (int) $data['denominator'],
                'is_active' => array_key_exists('is_active', $data)
                    ? (bool) $data['is_active']
                    : $conversion->is_active,
            ]);
            $conversion->save();

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: 'catalog.organization_product_unit_conversion.updated',
                subjectType: OrganizationProductUnitConversion::class,
                subjectId: $conversion->id,
                organization: $tenant->organization,
                actor: $actor,
                before: $before,
                after: $this->auditPayload($conversion->fresh()),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $conversion->fresh() ?? $conversion;
        });
    }

    public function deactivate(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProduct $organizationProduct,
        OrganizationProductUnitConversion $conversion,
    ): OrganizationProductUnitConversion {
        return $this->setActive($tenant, $actor, $request, $organizationProduct, $conversion, false, 'catalog.organization_product_unit_conversion.deactivated');
    }

    public function reactivate(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProduct $organizationProduct,
        OrganizationProductUnitConversion $conversion,
    ): OrganizationProductUnitConversion {
        return $this->setActive($tenant, $actor, $request, $organizationProduct, $conversion, true, 'catalog.organization_product_unit_conversion.reactivated');
    }

    /**
     * Exact preview text using pure UnitConversion math.
     *
     * @return array{preview: string, derived_reciprocal: string|null}
     */
    public function preview(string $fromUnit, string $toUnit, int $numerator, int $denominator): array
    {
        $this->assertDistinctUnits($fromUnit, $toUnit);
        $this->assertPositiveRatio($numerator, $denominator);

        $preview = UnitConversionPreview::make($fromUnit, $toUnit, $numerator, $denominator);

        return [
            'preview' => $preview['preview'],
            'derived_reciprocal' => $preview['derived_reciprocal'],
        ];
    }

    private function setActive(
        TenantContext $tenant,
        User $actor,
        Request $request,
        OrganizationProduct $organizationProduct,
        OrganizationProductUnitConversion $conversion,
        bool $active,
        string $action,
    ): OrganizationProductUnitConversion {
        $this->assertTenantOwnsProduct($tenant, $organizationProduct);
        $this->assertConversionBelongsToProduct($organizationProduct, $conversion);

        return DB::transaction(function () use ($tenant, $actor, $request, $conversion, $active, $action): OrganizationProductUnitConversion {
            $before = $this->auditPayload($conversion);
            $conversion->update(['is_active' => $active]);

            $this->auditor->append(
                parentAccount: ParentAccount::query()->whereKey($tenant->parentAccountId)->firstOrFail(),
                action: $action,
                subjectType: OrganizationProductUnitConversion::class,
                subjectId: $conversion->id,
                organization: $tenant->organization,
                actor: $actor,
                before: $before,
                after: $this->auditPayload($conversion->fresh()),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $conversion->fresh() ?? $conversion;
        });
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

    private function assertConversionBelongsToProduct(
        OrganizationProduct $organizationProduct,
        OrganizationProductUnitConversion $conversion,
    ): void {
        if (
            $conversion->organization_product_id !== $organizationProduct->id
            || $conversion->organization_id !== $organizationProduct->organization_id
            || $conversion->parent_account_id !== $organizationProduct->parent_account_id
        ) {
            abort(404);
        }
    }

    private function assertDistinctUnits(string $fromUnit, string $toUnit): void
    {
        if ($fromUnit === $toUnit) {
            throw ValidationException::withMessages([
                'to_unit' => 'From unit and to unit must be different.',
            ]);
        }
    }

    private function assertPositiveRatio(int $numerator, int $denominator): void
    {
        if ($numerator < 1) {
            throw ValidationException::withMessages([
                'numerator' => 'Numerator must be greater than zero.',
            ]);
        }

        if ($denominator < 1) {
            throw ValidationException::withMessages([
                'denominator' => 'Denominator must be greater than zero.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(?OrganizationProductUnitConversion $conversion): array
    {
        if ($conversion === null) {
            return [];
        }

        return [
            'id' => $conversion->id,
            'organization_product_id' => $conversion->organization_product_id,
            'from_unit' => $conversion->from_unit->value,
            'to_unit' => $conversion->to_unit->value,
            'numerator' => $conversion->numerator,
            'denominator' => $conversion->denominator,
            'is_active' => $conversion->is_active,
        ];
    }
}
