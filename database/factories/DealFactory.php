<?php

namespace Database\Factories;

use App\Enums\DealStage;
use App\Models\Company;
use App\Models\Deal;
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
            'name' => fake()->sentence(3),
            'company_id' => Company::factory(),
            'primary_contact_id' => null,
            'owner_id' => fn (array $attributes): int => (int) Company::query()->findOrFail($attributes['company_id'])->owner_id,
            'stage' => DealStage::Lead,
            'amount_cents' => fake()->optional()->numberBetween(50_000, 2_500_000),
            'expected_close_date' => fake()->optional()->dateTimeBetween('now', '+3 months'),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
