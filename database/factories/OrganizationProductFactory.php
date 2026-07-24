<?php

namespace Database\Factories;

use App\Enums\OverheadMode;
use App\Enums\PricingMethod;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationProduct>
 */
class OrganizationProductFactory extends Factory
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
            'product_id' => fn (array $attributes): int => Product::factory()->create([
                'parent_account_id' => $attributes['parent_account_id'],
            ])->id,
            'display_name' => null,
            'is_available' => true,
            'lead_time_days' => null,
            'notes' => null,
            'material_cost_micro_units' => 0,
            'labor_cost_micro_units' => 0,
            'overhead_mode' => OverheadMode::None,
            'overhead_amount_micro_units' => 0,
            'overhead_rate_basis_points' => 0,
            'pricing_method' => PricingMethod::Markup,
            'markup_basis_points' => 0,
            'target_margin_basis_points' => 0,
            'fixed_price_cents' => null,
            'minimum_price_cents' => null,
            'allow_price_override' => false,
            'currency_code' => 'USD',
            'pricing_version' => 1,
        ];
    }
}
