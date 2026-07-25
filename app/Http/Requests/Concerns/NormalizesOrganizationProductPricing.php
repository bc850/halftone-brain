<?php

namespace App\Http\Requests\Concerns;

use App\Enums\OverheadMode;
use App\Enums\PricingMethod;
use App\Support\Money;
use App\Support\Pricing\InvalidPricingException;
use App\Support\Pricing\PricingCalculator;
use App\Support\Pricing\PricingInput;
use App\Support\Pricing\PricingResult;
use Illuminate\Validation\ValidationException;

trait NormalizesOrganizationProductPricing
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeOrganizationPricing(array $data, bool $requireCompletePricing): array
    {
        $overheadMode = OverheadMode::from((string) $data['overhead_mode']);
        $pricingMethod = PricingMethod::from((string) $data['pricing_method']);

        $material = Money::dollarsToMicroUnits((string) ($data['material_cost'] ?? '0'));
        $labor = Money::dollarsToMicroUnits((string) ($data['labor_cost'] ?? '0'));
        $overheadAmount = array_key_exists('overhead_amount', $data) && $data['overhead_amount'] !== null && $data['overhead_amount'] !== ''
            ? Money::dollarsToMicroUnits((string) $data['overhead_amount'])
            : 0;
        $overheadRate = array_key_exists('overhead_rate_percent', $data) && $data['overhead_rate_percent'] !== null && $data['overhead_rate_percent'] !== ''
            ? Money::percentToBasisPoints((string) $data['overhead_rate_percent'])
            : 0;
        $markup = array_key_exists('markup_percent', $data) && $data['markup_percent'] !== null && $data['markup_percent'] !== ''
            ? Money::percentToBasisPoints((string) $data['markup_percent'])
            : 0;
        $targetMargin = array_key_exists('target_margin_percent', $data) && $data['target_margin_percent'] !== null && $data['target_margin_percent'] !== ''
            ? Money::percentToBasisPoints((string) $data['target_margin_percent'])
            : 0;
        $fixedPrice = array_key_exists('fixed_price', $data) && $data['fixed_price'] !== null && $data['fixed_price'] !== ''
            ? Money::dollarsToCents((string) $data['fixed_price'])
            : null;
        $minimumPrice = array_key_exists('minimum_price', $data) && $data['minimum_price'] !== null && $data['minimum_price'] !== ''
            ? Money::dollarsToCents((string) $data['minimum_price'])
            : null;

        $normalized = [
            'material_cost_micro_units' => $material,
            'labor_cost_micro_units' => $labor,
            'overhead_mode' => $overheadMode->value,
            'overhead_amount_micro_units' => $overheadAmount,
            'overhead_rate_basis_points' => $overheadRate,
            'pricing_method' => $pricingMethod->value,
            'markup_basis_points' => $markup,
            'target_margin_basis_points' => $targetMargin,
            'fixed_price_cents' => $fixedPrice,
            'minimum_price_cents' => $minimumPrice,
            'allow_price_override' => (bool) ($data['allow_price_override'] ?? false),
            'currency_code' => PricingCalculator::CURRENCY_USD,
        ];

        foreach ([
            'material_cost', 'labor_cost', 'overhead_amount', 'overhead_rate_percent',
            'markup_percent', 'target_margin_percent', 'fixed_price', 'minimum_price',
        ] as $key) {
            unset($data[$key]);
        }

        $merged = [...$data, ...$normalized];

        if ($requireCompletePricing) {
            $this->assertPricingConfiguration($merged);
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function assertPricingConfiguration(array $data, string $quantity = '1'): PricingResult
    {
        try {
            $input = new PricingInput(
                materialCostMicroUnits: (int) $data['material_cost_micro_units'],
                laborCostMicroUnits: (int) $data['labor_cost_micro_units'],
                overheadMode: OverheadMode::from((string) $data['overhead_mode']),
                overheadAmountMicroUnits: (int) $data['overhead_amount_micro_units'],
                overheadRateBasisPoints: (int) $data['overhead_rate_basis_points'],
                pricingMethod: PricingMethod::from((string) $data['pricing_method']),
                markupBasisPoints: (int) $data['markup_basis_points'],
                targetMarginBasisPoints: (int) $data['target_margin_basis_points'],
                fixedPriceCents: $data['fixed_price_cents'] !== null ? (int) $data['fixed_price_cents'] : null,
                minimumPriceCents: $data['minimum_price_cents'] !== null ? (int) $data['minimum_price_cents'] : null,
                allowPriceOverride: (bool) $data['allow_price_override'],
                requestedOverridePriceCents: null,
                quantity: $quantity,
                currencyCode: (string) ($data['currency_code'] ?? PricingCalculator::CURRENCY_USD),
                pricingVersion: max(1, (int) ($data['pricing_version'] ?? 1)),
            );

            $result = (new PricingCalculator)->calculate($input);
        } catch (InvalidPricingException $exception) {
            throw ValidationException::withMessages([
                'pricing' => $exception->getMessage(),
            ]);
        }

        if ($result->belowMinimum) {
            throw ValidationException::withMessages([
                'minimum_price' => 'The calculated selling price cannot be below the minimum selling price.',
            ]);
        }

        return $result;
    }
}
