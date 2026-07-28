<?php

namespace Database\Factories;

use App\Enums\QuoteDeliveryStatus;
use App\Models\Quote;
use App\Models\QuoteDelivery;
use App\Models\QuoteDeliveryEvent;
use App\Models\QuoteRevision;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QuoteDeliveryEvent>
 */
class QuoteDeliveryEventFactory extends Factory
{
    protected $model = QuoteDeliveryEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quote_delivery_id' => QuoteDelivery::factory(),
            'quote_revision_id' => fn (array $attributes): int => (int) QuoteDelivery::query()
                ->whereKey($attributes['quote_delivery_id'])
                ->value('quote_revision_id'),
            'quote_id' => fn (array $attributes): int => (int) QuoteRevision::query()
                ->whereKey($attributes['quote_revision_id'])
                ->value('quote_id'),
            'parent_account_id' => fn (array $attributes): int => (int) Quote::query()
                ->whereKey($attributes['quote_id'])
                ->value('parent_account_id'),
            'organization_id' => fn (array $attributes): int => (int) Quote::query()
                ->whereKey($attributes['quote_id'])
                ->value('organization_id'),
            'from_status' => null,
            'to_status' => QuoteDeliveryStatus::Pending,
            'occurred_at' => now(),
            'correlation_id' => (string) Str::uuid(),
        ];
    }
}
