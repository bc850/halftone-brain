<?php

namespace Database\Factories;

use App\Enums\DealStage;
use App\Models\Company;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\OrganizationCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
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
            'organization_company_id' => function (array $attributes): int {
                $existingId = OrganizationCompany::query()
                    ->where('organization_id', $attributes['organization_id'])
                    ->where('company_id', $attributes['company_id'])
                    ->value('id');

                if ($existingId !== null) {
                    return (int) $existingId;
                }

                return (int) OrganizationCompany::factory()->create([
                    'organization_id' => $attributes['organization_id'],
                    'company_id' => $attributes['company_id'],
                    'parent_account_id' => $attributes['parent_account_id'],
                ])->id;
            },
            'primary_contact_id' => null,
            'owner_id' => fn (array $attributes): int => (int) Company::query()
                ->whereKey($attributes['company_id'])
                ->value('owner_id'),
            'name' => fake()->sentence(3),
            'stage' => DealStage::Lead,
            'amount_cents' => fake()->optional()->numberBetween(50_000, 2_500_000),
            'expected_close_date' => fake()->optional()->dateTimeBetween('now', '+3 months'),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
