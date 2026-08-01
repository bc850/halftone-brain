<?php

namespace Database\Factories;

use App\Enums\IntegrationProvider;
use App\Enums\IntegrationProviderReceiptDiscoveryMethod;
use App\Enums\IntegrationRemoteResourceType;
use App\Models\IntegrationOutboxDelivery;
use App\Models\IntegrationProviderReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationProviderReceipt>
 */
class IntegrationProviderReceiptFactory extends Factory
{
    protected $model = IntegrationProviderReceipt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $remoteId = 'fake_item_'.fake()->unique()->numerify('########');

        return [
            'integration_outbox_delivery_id' => IntegrationOutboxDelivery::factory(),
            'parent_account_id' => fn (array $attributes): int => (int) IntegrationOutboxDelivery::query()
                ->whereKey($attributes['integration_outbox_delivery_id'])
                ->value('parent_account_id'),
            'organization_id' => fn (array $attributes): int => (int) IntegrationOutboxDelivery::query()
                ->whereKey($attributes['integration_outbox_delivery_id'])
                ->value('organization_id'),
            'provider' => IntegrationProvider::Monday,
            'remote_resource_type' => IntegrationRemoteResourceType::Item,
            'remote_id' => $remoteId,
            'remote_board_id' => 'fake_board_'.fake()->numerify('######'),
            'remote_url' => 'https://monday.test/boards/fake/pulses/'.$remoteId,
            'provider_request_id' => 'fake_req_'.fake()->bothify('????####'),
            'idempotency_replayed' => false,
            'api_version' => '2026-07',
            'linked_at' => now(),
            'discovery_method' => IntegrationProviderReceiptDiscoveryMethod::Created,
        ];
    }
}
