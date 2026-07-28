<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesTaxRatePercent;
use App\Models\OrganizationTaxRate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationTaxRateRequest extends FormRequest
{
    use NormalizesTaxRatePercent;

    public function authorize(): bool
    {
        return $this->user()?->can('create', OrganizationTaxRate::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'jurisdiction_code' => ['required', 'string', 'max:100'],
            'display_name' => ['required', 'string', 'max:255'],
            'rate_percent' => $this->ratePercentRules(),
            'effective_from' => ['required', 'date'],
            'effective_through' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'country' => ['required', 'string', 'size:2'],
            'state' => ['nullable', 'string', 'max:10'],
            'county' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'source_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
