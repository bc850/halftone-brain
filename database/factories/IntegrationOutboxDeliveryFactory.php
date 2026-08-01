<?php

namespace Database\Factories;

use App\Enums\IntegrationOutboxDeliveryStatus;
use App\Models\IntegrationOutbox;
use App\Models\IntegrationOutboxDelivery;
use App\Support\Integrations\Outbox\IntegrationDeliveryIdempotency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationOutboxDelivery>
 */
class IntegrationOutboxDeliveryFactory extends Factory
{
    protected $model = IntegrationOutboxDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $consumerKey = 'integrations.diagnostic.accepted_quote_probe';

        return [
            'integration_outbox_id' => IntegrationOutbox::factory(),
            'parent_account_id' => fn (array $attributes): ?int => IntegrationOutbox::query()
                ->whereKey($attributes['integration_outbox_id'])
                ->value('parent_account_id'),
            'organization_id' => fn (array $attributes): ?int => IntegrationOutbox::query()
                ->whereKey($attributes['integration_outbox_id'])
                ->value('organization_id'),
            'consumer_key' => $consumerKey,
            'idempotency_key' => fn (array $attributes): string => IntegrationDeliveryIdempotency::design(
                (int) $attributes['integration_outbox_id'],
                (string) $attributes['consumer_key'],
            ),
            'status' => IntegrationOutboxDeliveryStatus::Pending,
            'attempt_count' => 0,
            'available_at' => now(),
            'correlation_id' => fn (array $attributes): string => (string) IntegrationOutbox::query()
                ->whereKey($attributes['integration_outbox_id'])
                ->value('correlation_id'),
        ];
    }
}
