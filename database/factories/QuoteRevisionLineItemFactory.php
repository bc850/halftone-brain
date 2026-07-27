<?php

namespace Database\Factories;

use App\Enums\QuoteLineDiscountMethod;
use App\Enums\QuoteLineType;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionLineItem;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuoteRevisionLineItem>
 */
class QuoteRevisionLineItemFactory extends Factory
{
    protected $model = QuoteRevisionLineItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quote_revision_id' => QuoteRevision::factory(),
            'position' => 1,
            'line_type' => QuoteLineType::Custom,
            'name_snapshot' => fake()->words(3, true),
            'customer_description_snapshot' => fake()->sentence(),
            'quantity_scaled' => ComponentCostEstimator::quantityToScaled('1'),
            'uom_snapshot' => 'each',
            'calculated_unit_price_cents' => 1000,
            'final_unit_price_cents' => 1000,
            'extended_price_cents' => 1000,
            'line_discount_method' => QuoteLineDiscountMethod::None,
            'line_discount_value' => 0,
            'line_discount_amount_cents' => 0,
            'net_line_total_cents' => 1000,
            'is_taxable' => true,
            'price_override' => false,
            'below_minimum' => false,
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

    public function section(): static
    {
        return $this->state(fn (): array => [
            'line_type' => QuoteLineType::Section,
            'quantity_scaled' => 0,
            'uom_snapshot' => null,
            'calculated_unit_price_cents' => null,
            'final_unit_price_cents' => null,
            'extended_price_cents' => 0,
            'line_discount_method' => QuoteLineDiscountMethod::None,
            'net_line_total_cents' => 0,
            'is_taxable' => false,
        ]);
    }

    public function note(): static
    {
        return $this->state(fn (): array => [
            'line_type' => QuoteLineType::Note,
            'quantity_scaled' => 0,
            'uom_snapshot' => null,
            'calculated_unit_price_cents' => null,
            'final_unit_price_cents' => null,
            'extended_price_cents' => 0,
            'net_line_total_cents' => 0,
            'is_taxable' => false,
        ]);
    }
}
