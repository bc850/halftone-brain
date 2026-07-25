<?php

namespace Database\Factories;

use App\Enums\UnitOfMeasure;
use App\Models\OrganizationProduct;
use App\Models\OrganizationProductUnitConversion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationProductUnitConversion>
 */
class OrganizationProductUnitConversionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_product_id' => OrganizationProduct::factory(),
            'parent_account_id' => fn (array $attributes): int => (int) OrganizationProduct::query()
                ->whereKey($attributes['organization_product_id'])
                ->value('parent_account_id'),
            'organization_id' => fn (array $attributes): int => (int) OrganizationProduct::query()
                ->whereKey($attributes['organization_product_id'])
                ->value('organization_id'),
            'from_unit' => UnitOfMeasure::Sheet,
            'to_unit' => UnitOfMeasure::SquareFoot,
            'numerator' => 32,
            'denominator' => 1,
            'is_active' => true,
        ];
    }
}
