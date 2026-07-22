<?php

namespace Database\Factories;

use App\Enums\UnitOfMeasure;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Vendor;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $trueCostMicroUnits = fake()->numberBetween(100_000, 5_000_000);
        $markupBasisPoints = fake()->numberBetween(2_000, 8_000);
        $listPriceCents = Money::suggestedListPriceCents($trueCostMicroUnits, $markupBasisPoints);

        return [
            'name' => fake()->sentence(3).' Sign',
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####??')),
            'vendor_sku' => fake()->optional()->bothify('VEN-####'),
            'vendor_id' => Vendor::factory(),
            'product_category_id' => ProductCategory::factory(),
            'unit_of_measure' => fake()->randomElement(UnitOfMeasure::cases()),
            'true_cost_micro_units' => $trueCostMicroUnits,
            'markup_basis_points' => $markupBasisPoints,
            'list_price_cents' => $listPriceCents,
            'description' => fake()->optional()->sentence(),
            'notes' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
