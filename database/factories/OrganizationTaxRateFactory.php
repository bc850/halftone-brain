<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationTaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationTaxRate>
 */
class OrganizationTaxRateFactory extends Factory
{
    protected $model = OrganizationTaxRate::class;

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
            'country' => 'US',
            'state' => null,
            'county' => null,
            'city' => null,
            'postal_code' => null,
            'jurisdiction_code' => 'test-jurisdiction',
            'display_name' => 'Test jurisdiction',
            'rate_ppm' => 70_000,
            'effective_from' => '2026-01-01',
            'effective_through' => null,
            'is_active' => true,
            'source_note' => 'Entered for testing; not a legal rate.',
        ];
    }
}
