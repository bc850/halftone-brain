<?php

namespace Database\Factories;

use App\Enums\UnitOfMeasure;
use App\Enums\VendorProductOfferingStatus;
use App\Models\ParentAccount;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\VendorProductOffering;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VendorProductOffering>
 */
class VendorProductOfferingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_account_id' => ParentAccount::factory(),
            'product_id' => fn (array $attributes): int => Product::factory()->create([
                'parent_account_id' => $attributes['parent_account_id'],
            ])->id,
            'vendor_id' => fn (array $attributes): int => Vendor::factory()->create([
                'parent_account_id' => $attributes['parent_account_id'],
            ])->id,
            'vendor_sku' => strtoupper(fake()->unique()->bothify('VSKU-####??')),
            'vendor_description' => fake()->optional()->sentence(),
            'manufacturer' => fake()->optional()->company(),
            'manufacturer_part_number' => fake()->optional()->bothify('MPN-####'),
            'product_url' => fake()->optional()->url(),
            'purchase_uom' => UnitOfMeasure::Sheet,
            'package_quantity_scaled' => ComponentCostEstimator::QUANTITY_SCALE_FACTOR,
            'minimum_order_quantity_scaled' => null,
            'lead_time_days' => fake()->optional()->numberBetween(1, 30),
            'status' => VendorProductOfferingStatus::Active,
            'discontinued_at' => null,
        ];
    }

    public function discontinued(): static
    {
        return $this->state(fn (): array => [
            'status' => VendorProductOfferingStatus::Discontinued,
            'discontinued_at' => now(),
        ]);
    }
}
