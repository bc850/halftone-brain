<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationProductSource;
use App\Models\OrganizationProductSourcePriceEvent;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationProductSourcePriceEvent>
 */
class OrganizationProductSourcePriceEventFactory extends Factory
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
            'organization_product_source_id' => fn (array $attributes): int => OrganizationProductSource::factory()->create([
                'organization_id' => $attributes['organization_id'],
                'parent_account_id' => $attributes['parent_account_id'],
            ])->id,
            'package_price_micro_units' => Money::dollarsToMicroUnits('800'),
            'effective_purchase_unit_cost_micro_units' => Money::dollarsToMicroUnits('80'),
            'currency_code' => 'USD',
            'actor_user_id' => null,
            'note' => null,
            'recorded_at' => now(),
        ];
    }
}
