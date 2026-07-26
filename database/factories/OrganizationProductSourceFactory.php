<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\OrganizationProductSource;
use App\Models\VendorProductOffering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationProductSource>
 */
class OrganizationProductSourceFactory extends Factory
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
            'vendor_product_offering_id' => fn (array $attributes): int => VendorProductOffering::factory()->create([
                'parent_account_id' => $attributes['parent_account_id'],
                'product_id' => (int) OrganizationProduct::query()
                    ->whereKey($attributes['organization_product_id'])
                    ->value('product_id'),
            ])->id,
            'current_package_price_micro_units' => null,
            'currency_code' => 'USD',
            'price_version' => 1,
            'is_active' => true,
        ];
    }
}
