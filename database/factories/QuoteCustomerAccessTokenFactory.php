<?php

namespace Database\Factories;

use App\Enums\QuoteCustomerAccessTokenPurpose;
use App\Models\Membership;
use App\Models\Quote;
use App\Models\QuoteCustomerAccessToken;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionDocument;
use App\Support\Quotes\Security\QuoteCustomerAccessTokenGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuoteCustomerAccessToken>
 */
class QuoteCustomerAccessTokenFactory extends Factory
{
    protected $model = QuoteCustomerAccessToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $generator = new QuoteCustomerAccessTokenGenerator;

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
            'token_hash' => $generator->hashToken($generator->generateRaw()),
            'purpose' => QuoteCustomerAccessTokenPurpose::ViewAndRespond,
            'expires_at' => now()->addDays(14),
            'created_by_membership_id' => fn (array $attributes): int => (int) Membership::factory()->create([
                'organization_id' => $attributes['organization_id'],
            ])->id,
            'created_by_user_id' => fn (array $attributes): int => (int) Membership::query()
                ->whereKey($attributes['created_by_membership_id'])
                ->value('user_id'),
            'view_count' => 0,
        ];
    }

    public function revoked(string $reason = 'superseded'): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
            'revoke_reason' => $reason,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
