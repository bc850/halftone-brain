<?php

namespace Database\Factories;

use App\Enums\PermissionEffect;
use App\Models\Membership;
use App\Models\MembershipPermissionOverride;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MembershipPermissionOverride>
 */
class MembershipPermissionOverrideFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'membership_id' => Membership::factory(),
            'permission_id' => Permission::factory(),
            'effect' => PermissionEffect::Allow,
            'reason' => fake()->sentence(),
            'created_by_user_id' => User::factory(),
        ];
    }
}
