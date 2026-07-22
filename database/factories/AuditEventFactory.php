<?php

namespace Database\Factories;

use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\ParentAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditEvent>
 */
class AuditEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_account_id' => ParentAccount::factory(),
            'organization_id' => null,
            'actor_user_id' => User::factory(),
            'action' => fake()->randomElement(['created', 'updated', 'deleted']),
            'subject_type' => Company::class,
            'subject_id' => null,
            'before_json' => null,
            'after_json' => ['name' => fake()->company()],
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'correlation_id' => fake()->uuid(),
        ];
    }
}
