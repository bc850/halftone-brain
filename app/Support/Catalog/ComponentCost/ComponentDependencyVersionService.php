<?php

namespace App\Support\Catalog\ComponentCost;

use App\Models\OrganizationProduct;
use App\Models\OrganizationProductComponent;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Bumps components_version on finished OrganizationProducts when material
 * dependencies may calculate differently. Never touches pricing_version or
 * material_cost_micro_units.
 */
final class ComponentDependencyVersionService
{
    /**
     * Increment components_version once for every distinct finished parent that
     * has an active component row pointing at the given material OP.
     *
     * @return array{invalidated_count: int, correlation_id: string|null, finished_ids: array<int, int>}
     */
    public function invalidateDependentsOfMaterial(OrganizationProduct $material): array
    {
        $finishedIds = OrganizationProductComponent::query()
            ->where('component_organization_product_id', $material->id)
            ->where('organization_id', $material->organization_id)
            ->where('parent_account_id', $material->parent_account_id)
            ->where('is_active', true)
            ->distinct()
            ->orderBy('organization_product_id')
            ->pluck('organization_product_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $this->bumpFinishedIds($finishedIds);
    }

    /**
     * Increment components_version for every distinct finished OP under the
     * shared product master that may be affected by an item_kind change.
     *
     * @return array{invalidated_count: int, correlation_id: string|null, finished_ids: array<int, int>}
     */
    public function invalidateDependentsOfProductMaster(Product $product): array
    {
        $materialOpIds = OrganizationProduct::query()
            ->where('product_id', $product->id)
            ->where('parent_account_id', $product->parent_account_id)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($materialOpIds === []) {
            return [
                'invalidated_count' => 0,
                'correlation_id' => null,
                'finished_ids' => [],
            ];
        }

        $finishedIds = OrganizationProductComponent::query()
            ->whereIn('component_organization_product_id', $materialOpIds)
            ->where('parent_account_id', $product->parent_account_id)
            ->where('is_active', true)
            ->distinct()
            ->orderBy('organization_product_id')
            ->pluck('organization_product_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $this->bumpFinishedIds($finishedIds);
    }

    /**
     * Increment a single finished parent's components_version (component graph mutation).
     *
     * @return int The new components_version
     */
    public function bumpFinishedParent(OrganizationProduct $finished): int
    {
        $locked = OrganizationProduct::query()
            ->whereKey($finished->id)
            ->lockForUpdate()
            ->firstOrFail();

        $next = $this->nextVersion($locked->components_version);
        $locked->forceFill(['components_version' => $next])->save();

        return $next;
    }

    /**
     * @param  array<int, int>  $finishedIds
     * @return array{invalidated_count: int, correlation_id: string|null, finished_ids: array<int, int>}
     */
    private function bumpFinishedIds(array $finishedIds): array
    {
        $finishedIds = array_values($finishedIds);
        if ($finishedIds === []) {
            return [
                'invalidated_count' => 0,
                'correlation_id' => null,
                'finished_ids' => [],
            ];
        }

        $correlationId = (string) Str::uuid();

        /** @var Collection<int, OrganizationProduct> $locked */
        $locked = OrganizationProduct::query()
            ->whereIn('id', $finishedIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($finishedIds as $id) {
            $row = $locked->get($id);
            if ($row === null) {
                continue;
            }

            $row->forceFill([
                'components_version' => $this->nextVersion($row->components_version),
            ])->save();
        }

        return [
            'invalidated_count' => count($finishedIds),
            'correlation_id' => $correlationId,
            'finished_ids' => $finishedIds,
        ];
    }

    private function nextVersion(int $current): int
    {
        if ($current < 1) {
            throw new InvalidArgumentException('components_version must be at least 1.');
        }

        if ($current >= PHP_INT_MAX) {
            throw new HttpException(500, 'components_version overflow.');
        }

        return $current + 1;
    }
}
