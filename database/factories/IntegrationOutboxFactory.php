<?php

namespace Database\Factories;

use App\Enums\IntegrationOutboxStatus;
use App\Models\IntegrationOutbox;
use App\Models\Organization;
use App\Support\Quotes\Acceptance\QuoteAcceptanceAtomicityContract;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IntegrationOutbox>
 */
class IntegrationOutboxFactory extends Factory
{
    protected $model = IntegrationOutbox::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $revisionId = fake()->unique()->numberBetween(1, 1_000_000);

        return [
            'organization_id' => Organization::factory(),
            'parent_account_id' => fn (array $attributes): int => (int) Organization::query()
                ->whereKey($attributes['organization_id'])
                ->value('parent_account_id'),
            'aggregate_type' => 'quote_revision',
            'aggregate_id' => $revisionId,
            'event_type' => QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE,
            'schema_version' => 1,
            'payload_json' => [
                'quote_revision_id' => $revisionId,
                'quote_id' => $revisionId + 1,
                'organization_id' => 1,
            ],
            'idempotency_key' => (new QuoteAcceptanceAtomicityContract)->designIdempotencyKey($revisionId),
            'status' => IntegrationOutboxStatus::Pending,
            'attempt_count' => 0,
            'available_at' => now(),
            'correlation_id' => (string) Str::uuid(),
        ];
    }
}
