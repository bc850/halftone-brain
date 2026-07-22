<?php

namespace Database\Factories;

use App\Models\NumberSequence;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NumberSequence>
 */
class NumberSequenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'sequence_key' => fake()->unique()->slug(1),
            'prefix' => '',
            'next_number' => 1,
            'pad_length' => 5,
        ];
    }
}
