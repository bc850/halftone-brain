<?php

namespace Database\Factories;

use App\Enums\QuoteApprovalDecisionType;
use App\Models\Membership;
use App\Models\QuoteApprovalDecision;
use App\Models\QuoteApprovalRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuoteApprovalDecision>
 */
class QuoteApprovalDecisionFactory extends Factory
{
    protected $model = QuoteApprovalDecision::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quote_approval_request_id' => QuoteApprovalRequest::factory(),
            'quote_id' => fn (array $attributes): int => (int) QuoteApprovalRequest::query()
                ->whereKey($attributes['quote_approval_request_id'])
                ->value('quote_id'),
            'quote_revision_id' => fn (array $attributes): int => (int) QuoteApprovalRequest::query()
                ->whereKey($attributes['quote_approval_request_id'])
                ->value('quote_revision_id'),
            'parent_account_id' => fn (array $attributes): int => (int) QuoteApprovalRequest::query()
                ->whereKey($attributes['quote_approval_request_id'])
                ->value('parent_account_id'),
            'organization_id' => fn (array $attributes): int => (int) QuoteApprovalRequest::query()
                ->whereKey($attributes['quote_approval_request_id'])
                ->value('organization_id'),
            'approver_membership_id' => fn (array $attributes): int => (int) Membership::factory()->create([
                'organization_id' => $attributes['organization_id'],
            ])->id,
            'approver_user_id' => fn (array $attributes): int => (int) Membership::query()
                ->whereKey($attributes['approver_membership_id'])
                ->value('user_id'),
            'decision' => QuoteApprovalDecisionType::Approved,
            'reason' => null,
            'decided_at' => now(),
        ];
    }

    public function rejected(string $reason = 'Margin too low.'): static
    {
        return $this->state(fn (): array => [
            'decision' => QuoteApprovalDecisionType::Rejected,
            'reason' => $reason,
        ]);
    }
}
