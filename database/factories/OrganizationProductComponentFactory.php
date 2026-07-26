<?php

namespace Database\Factories;

use App\Enums\UnitOfMeasure;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\OrganizationProductComponent;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationProductComponent>
 */
class OrganizationProductComponentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'parent_account_id' => fn (array $attributes): int => (int) Organization::query()
                ->whereKey($attributes['organization_id'])
                ->value('parent_account_id'),
            'organization_product_id' => fn (array $attributes): int => OrganizationProduct::factory()->create([
                'organization_id' => $attributes['organization_id'],
                'parent_account_id' => $attributes['parent_account_id'],
            ])->id,
            'component_organization_product_id' => fn (array $attributes): int => OrganizationProduct::factory()->create([
                'organization_id' => $attributes['organization_id'],
                'parent_account_id' => $attributes['parent_account_id'],
                'is_purchasable' => true,
                'purchase_unit_of_measure' => UnitOfMeasure::Sheet,
                'purchase_cost_micro_units' => 800_000,
            ])->id,
            'quantity_scaled' => ComponentCostEstimator::QUANTITY_SCALE_FACTOR,
            'usage_uom' => UnitOfMeasure::SquareFoot,
            'waste_basis_points' => 0,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
