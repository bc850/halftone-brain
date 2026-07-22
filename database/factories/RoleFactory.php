<?php

namespace Database\Factories;

use App\Enums\RoleScope;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => fake()->jobTitle(),
            'scope' => RoleScope::System,
            'parent_account_id' => null,
            'organization_id' => null,
        ];
    }
}
