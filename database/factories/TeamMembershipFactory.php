<?php

namespace Database\Factories;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Team;
use App\Models\TeamMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamMembership>
 */
class TeamMembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'team_id' => function (array $attributes): int {
                $organizationId = $attributes['organization_id'];
                assert(is_int($organizationId) || is_string($organizationId));

                /** @var Organization|null $organization */
                $organization = Organization::query()->find($organizationId);

                return Team::factory()->create([
                    'organization_id' => $organizationId,
                    'parent_account_id' => $organization?->parent_account_id,
                ])->id;
            },
            'membership_id' => function (array $attributes): int {
                return Membership::factory()->create([
                    'organization_id' => $attributes['organization_id'],
                ])->id;
            },
        ];
    }
}
