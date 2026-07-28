<?php

namespace Database\Factories;

use App\Enums\TaxSourcingStrategy;
use App\Models\Organization;
use App\Models\OrganizationTaxProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationTaxProfile>
 */
class OrganizationTaxProfileFactory extends Factory
{
    protected $model = OrganizationTaxProfile::class;

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
            'default_country' => 'US',
            'default_state' => null,
            'sourcing_strategy' => TaxSourcingStrategy::Delivery,
            'tax_calculation_enabled' => true,
            'is_active' => true,
            'configuration_version' => 1,
        ];
    }
}
