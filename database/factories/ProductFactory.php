<?php

namespace Database\Factories;

use App\Enums\ItemKind;
use App\Enums\ProductFamily;
use App\Enums\UnitOfMeasure;
use App\Models\ParentAccount;
use App\Models\Product;
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
            'parent_account_id' => ParentAccount::factory(),
            'name' => fake()->sentence(3).' Sign',
            'product_family' => ProductFamily::Other,
            'item_kind' => ItemKind::Product,
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####??')),
            // Optional category remains nullable; set explicitly when needed so parent matches.
            'product_category_id' => null,
            'unit_of_measure' => fake()->randomElement([
                UnitOfMeasure::Each,
                UnitOfMeasure::Sheet,
                UnitOfMeasure::SquareFoot,
                UnitOfMeasure::LinearFoot,
                UnitOfMeasure::Hour,
                UnitOfMeasure::Set,
            ]),
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

    public function material(): static
    {
        return $this->state(fn (array $attributes) => [
            'item_kind' => ItemKind::Material,
        ]);
    }

    public function service(): static
    {
        return $this->state(fn (array $attributes) => [
            'item_kind' => ItemKind::Service,
            'product_family' => ProductFamily::Service,
            'unit_of_measure' => UnitOfMeasure::Hour,
        ]);
    }
}
