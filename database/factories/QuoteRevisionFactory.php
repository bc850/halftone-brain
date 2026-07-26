<?php

namespace Database\Factories;

use App\Enums\QuoteRevisionStatus;
use App\Models\Quote;
use App\Models\QuoteRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuoteRevision>
 */
class QuoteRevisionFactory extends Factory
{
    protected $model = QuoteRevision::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quote_id' => Quote::factory(),
            'revision_number' => 1,
            'status' => QuoteRevisionStatus::Draft,
            'lock_version' => 1,
            'currency_code' => 'USD',
            'subtotal_cents' => 0,
            'discount_cents' => 0,
            'taxable_amount_cents' => 0,
            'tax_cents' => 0,
            'grand_total_cents' => 0,
            'approval_required' => false,
            'parent_account_id' => fn (array $attributes): int => (int) Quote::query()
                ->whereKey($attributes['quote_id'])
                ->value('parent_account_id'),
            'organization_id' => fn (array $attributes): int => (int) Quote::query()
                ->whereKey($attributes['quote_id'])
                ->value('organization_id'),
        ];
    }
}
