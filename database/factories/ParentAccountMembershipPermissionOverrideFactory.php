<?php

namespace Database\Factories;

use App\Enums\PermissionEffect;
use App\Models\ParentAccountMembership;
use App\Models\ParentAccountMembershipPermissionOverride;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParentAccountMembershipPermissionOverride>
 */
class ParentAccountMembershipPermissionOverrideFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_account_membership_id' => ParentAccountMembership::factory(),
            'permission_id' => Permission::factory(),
            'effect' => PermissionEffect::Allow,
            'reason' => fake()->sentence(),
            'created_by_user_id' => User::factory(),
        ];
    }
}
