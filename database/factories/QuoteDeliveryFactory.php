<?php

namespace Database\Factories;

use App\Enums\QuoteDeliveryChannel;
use App\Enums\QuoteDeliveryStatus;
use App\Models\Membership;
use App\Models\Quote;
use App\Models\QuoteDelivery;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QuoteDelivery>
 */
class QuoteDeliveryFactory extends Factory
{
    protected $model = QuoteDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quote_revision_document_id' => QuoteRevisionDocument::factory(),
            'quote_revision_id' => fn (array $attributes): int => (int) QuoteRevisionDocument::query()
                ->whereKey($attributes['quote_revision_document_id'])
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
            'channel' => QuoteDeliveryChannel::Email,
            'status' => QuoteDeliveryStatus::Pending,
            'recipient_name_snapshot' => 'Jamie Customer',
            'recipient_email_snapshot' => 'jamie@example.test',
            'idempotency_key' => (string) Str::uuid(),
            'requested_by_membership_id' => fn (array $attributes): int => (int) Membership::factory()->create([
                'organization_id' => $attributes['organization_id'],
            ])->id,
            'requested_by_user_id' => fn (array $attributes): int => (int) Membership::query()
                ->whereKey($attributes['requested_by_membership_id'])
                ->value('user_id'),
            'requested_at' => now(),
        ];
    }

    public function providerAccepted(): static
    {
        return $this->state(fn (): array => [
            'status' => QuoteDeliveryStatus::ProviderAccepted,
            'sent_at' => now(),
        ]);
    }

    public function manuallyRecorded(): static
    {
        return $this->state(fn (): array => [
            'channel' => QuoteDeliveryChannel::Manual,
            'status' => QuoteDeliveryStatus::ManuallyRecorded,
            'sent_at' => now(),
        ]);
    }
}
