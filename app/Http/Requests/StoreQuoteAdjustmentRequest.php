<?php

namespace App\Http\Requests;

use App\Enums\QuoteAdjustmentMethod;
use App\Enums\QuoteAdjustmentType;
use App\Http\Requests\Concerns\AuthorizesQuoteDraft;
use App\Http\Requests\Concerns\NormalizesQuoteAdjustmentValue;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuoteAdjustmentRequest extends FormRequest
{
    use AuthorizesQuoteDraft;
    use NormalizesQuoteAdjustmentValue;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expected_lock_version' => $this->lockVersionRules(),
            'adjustment_type' => ['required', Rule::enum(QuoteAdjustmentType::class)],
            'description' => ['required', 'string', 'max:255'],
            'method' => ['required', Rule::enum(QuoteAdjustmentMethod::class)],
            'value' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'is_taxable' => ['nullable', 'boolean'],
            'reason' => [
                Rule::requiredIf(fn (): bool => $this->input('adjustment_type') === QuoteAdjustmentType::QuoteDiscount->value),
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    public function adjustmentType(): QuoteAdjustmentType
    {
        return QuoteAdjustmentType::from((string) $this->validated('adjustment_type'));
    }

    public function adjustmentMethod(): QuoteAdjustmentMethod
    {
        return QuoteAdjustmentMethod::from((string) $this->validated('method'));
    }

    public function inputValue(): int
    {
        return $this->adjustmentInputValue($this->adjustmentMethod(), $this->validated('value'));
    }
}
