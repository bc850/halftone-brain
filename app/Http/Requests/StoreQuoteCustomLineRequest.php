<?php

namespace App\Http\Requests;

use App\Enums\UnitOfMeasure;
use App\Http\Requests\Concerns\AuthorizesQuoteDraft;
use App\Http\Requests\Concerns\NormalizesQuoteMoney;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuoteCustomLineRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'quantity' => ['required', ...$this->quantityRules()],
            'unit_price' => ['required', ...$this->dollarAmountRules()],
            'reason' => ['required', 'string', 'max:1000'],
            'uom' => ['nullable', Rule::enum(UnitOfMeasure::class)],
            'is_taxable' => ['nullable', 'boolean'],
            'customer_description' => ['nullable', 'string', 'max:20000'],
            'internal_description' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function unitPriceCents(): int
    {
        return (int) $this->centsFromDollars($this->validated('unit_price'), 'unit_price');
    }
}
