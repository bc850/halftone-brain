<?php

namespace Database\Factories;

use App\Enums\QuoteTaxCalculationOutcome;
use App\Enums\QuoteTaxCalculationSource;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionTaxCalculation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QuoteRevisionTaxCalculation>
 */
class QuoteRevisionTaxCalculationFactory extends Factory
{
    protected $model = QuoteRevisionTaxCalculation::class;

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
            'calculation_version' => 1,
            'outcome' => QuoteTaxCalculationOutcome::Calculated,
            'taxable_basis_cents' => 100_000,
            'rate_ppm' => 80_000,
            'tax_cents' => 8_000,
            'jurisdiction_snapshot_json' => [
                'jurisdiction_code' => 'test-jurisdiction',
                'display_name' => 'Test jurisdiction',
            ],
            'source' => QuoteTaxCalculationSource::ConfiguredRate,
            'is_override' => false,
            'calculator_version' => 'quote-tax-calculator-2c1',
            'calculated_at' => now(),
            'correlation_id' => (string) Str::uuid(),
        ];
    }

    public function reviewRequired(): static
    {
        return $this->state(fn (): array => [
            'outcome' => QuoteTaxCalculationOutcome::ReviewRequired,
            'rate_ppm' => null,
            'tax_cents' => 0,
        ]);
    }
}
