<?php

namespace Database\Factories;

use App\Enums\MembershipStatus;
use App\Models\ParentAccount;
use App\Models\ParentAccountMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParentAccountMembership>
 */
class ParentAccountMembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_account_id' => ParentAccount::factory(),
            'user_id' => User::factory(),
            'status' => MembershipStatus::Active,
        ];
    }
}
