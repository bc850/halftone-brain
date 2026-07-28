<?php

namespace Database\Factories;

use App\Enums\QuoteCustomerResponseSource;
use App\Enums\QuoteCustomerResponseType;
use App\Models\Quote;
use App\Models\QuoteCustomerResponseEvent;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QuoteCustomerResponseEvent>
 */
class QuoteCustomerResponseEventFactory extends Factory
{
    protected $model = QuoteCustomerResponseEvent::class;

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
            'response' => QuoteCustomerResponseType::Accepted,
            'source' => QuoteCustomerResponseSource::Customer,
            'typed_name_snapshot' => 'Jamie Customer',
            'customer_email_snapshot' => 'jamie@example.test',
            'terms_accepted' => true,
            'terms_document_checksum' => fn (array $attributes): string => (string) QuoteRevisionDocument::query()
                ->whereKey($attributes['quote_revision_document_id'])
                ->value('content_sha256'),
            'occurred_at' => now(),
            'correlation_id' => (string) Str::uuid(),
        ];
    }

    public function rejected(?string $reason = 'Not needed'): static
    {
        return $this->state(fn (): array => [
            'response' => QuoteCustomerResponseType::Rejected,
            'terms_accepted' => false,
            'typed_name_snapshot' => 'Jamie Customer',
            'rejection_reason' => $reason,
        ]);
    }
}
