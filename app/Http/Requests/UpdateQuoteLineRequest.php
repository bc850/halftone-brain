<?php

namespace App\Http\Requests;

use App\Enums\QuoteLineDiscountMethod;
use App\Http\Requests\Concerns\AuthorizesQuoteDraft;
use App\Http\Requests\Concerns\NormalizesQuoteMoney;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuoteLineRequest extends FormRequest
{
    use AuthorizesQuoteDraft;
    use NormalizesQuoteMoney;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expected_lock_version' => $this->lockVersionRules(),
            'name_snapshot' => ['sometimes', 'string', 'max:255'],
            'customer_description_snapshot' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'internal_description_snapshot' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'uom_snapshot' => ['sometimes', 'nullable', 'string', 'max:32'],
            'quantity' => ['sometimes', ...$this->quantityRules()],
            'is_taxable' => ['sometimes', 'boolean'],
            'final_unit_price' => ['sometimes', ...$this->dollarAmountRules()],
            'override_reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'line_discount_method' => ['sometimes', Rule::enum(QuoteLineDiscountMethod::class)],
            'line_discount_value' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * Translate the submitted subset into the domain service's update payload.
     *
     * @return array<string, mixed>
     */
    public function lineChanges(): array
    {
        $validated = $this->validated();
        unset($validated['expected_lock_version']);

        if (array_key_exists('final_unit_price', $validated)) {
            $validated['final_unit_price_cents'] = $this->centsFromDollars(
                $validated['final_unit_price'],
                'final_unit_price',
            );
            unset($validated['final_unit_price']);
        }

        return $validated;
    }

    public function requestedUnitPriceCents(): ?int
    {
        return array_key_exists('final_unit_price', $this->validated())
            ? $this->centsFromDollars($this->validated('final_unit_price'), 'final_unit_price')
            : null;
    }
}
