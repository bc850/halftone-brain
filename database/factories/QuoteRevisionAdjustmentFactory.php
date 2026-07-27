<?php

namespace Database\Factories;

use App\Enums\QuoteAdjustmentMethod;
use App\Enums\QuoteAdjustmentType;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionAdjustment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuoteRevisionAdjustment>
 */
class QuoteRevisionAdjustmentFactory extends Factory
{
    protected $model = QuoteRevisionAdjustment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quote_revision_id' => QuoteRevision::factory(),
            'position' => 1,
            'adjustment_type' => QuoteAdjustmentType::Fee,
            'description_snapshot' => 'Handling fee',
            'method' => QuoteAdjustmentMethod::Fixed,
            'input_value' => 500,
            'amount_cents' => 500,
            'is_taxable' => true,
            'approval_required' => false,
            'quote_id' => fn (array $attributes): int => (int) QuoteRevision::query()
                ->whereKey($attributes['quote_revision_id'])
                ->value('quote_id'),
            'parent_account_id' => fn (array $attributes): int => (int) QuoteRevision::query()
                ->whereKey($attributes['quote_revision_id'])
                ->value('parent_account_id'),
            'organization_id' => fn (array $attributes): int => (int) QuoteRevision::query()
                ->whereKey($attributes['quote_revision_id'])
                ->value('organization_id'),
        ];
    }

    public function quoteDiscountFixed(int $cents): static
    {
        return $this->state(fn (): array => [
            'adjustment_type' => QuoteAdjustmentType::QuoteDiscount,
            'description_snapshot' => 'Quote discount',
            'method' => QuoteAdjustmentMethod::Fixed,
            'input_value' => $cents,
            'amount_cents' => $cents,
            'is_taxable' => false,
        ]);
    }
}
