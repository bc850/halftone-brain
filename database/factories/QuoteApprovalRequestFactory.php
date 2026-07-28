<?php

namespace Database\Factories;

use App\Enums\QuoteApprovalRequestStatus;
use App\Models\Membership;
use App\Models\Quote;
use App\Models\QuoteApprovalRequest;
use App\Models\QuoteRevision;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QuoteApprovalRequest>
 */
class QuoteApprovalRequestFactory extends Factory
{
    protected $model = QuoteApprovalRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quote_revision_id' => QuoteRevision::factory(),
            'quote_id' => fn (array $attributes): int => (int) QuoteRevision::query()
                ->whereKey($attributes['quote_revision_id'])
                ->value('quote_id'),
            'parent_account_id' => fn (array $attributes): int => (int) Quote::query()
                ->whereKey($attributes['quote_id'])
                ->value('parent_account_id'),
            'organization_id' => fn (array $attributes): int => (int) Quote::query()
                ->whereKey($attributes['quote_id'])
                ->value('organization_id'),
            'requested_by_membership_id' => fn (array $attributes): int => (int) Membership::factory()->create([
                'organization_id' => $attributes['organization_id'],
            ])->id,
            'requested_by_user_id' => fn (array $attributes): int => (int) Membership::query()
                ->whereKey($attributes['requested_by_membership_id'])
                ->value('user_id'),
            'request_version' => 1,
            'status' => QuoteApprovalRequestStatus::Pending,
            'rule_snapshot_json' => ['reasons' => ['over_threshold']],
            'requested_at' => now(),
            'correlation_id' => (string) Str::uuid(),
        ];
    }
}
