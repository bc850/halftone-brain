<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Organization;
use App\Models\OrganizationCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationCompany>
 */
class OrganizationCompanyFactory extends Factory
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
            'company_id' => function (array $attributes): int {
                return (int) Company::factory()->create([
                    'parent_account_id' => $attributes['parent_account_id'],
                ])->id;
            },
            'lifecycle_status' => 'prospect',
            'relationship_status' => 'new',
            'tax_posture' => 'unknown',
            'is_flagged' => false,
            'credit_hold' => false,
        ];
    }
}
