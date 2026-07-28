<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesTaxRatePercent;
use App\Models\OrganizationTaxRate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SupersedeOrganizationTaxRateRequest extends FormRequest
{
    use NormalizesTaxRatePercent;

    public function authorize(): bool
    {
        $rate = $this->route('taxRate');

        return $rate instanceof OrganizationTaxRate
            && ($this->user()?->can('update', $rate) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rate_percent' => $this->ratePercentRules(),
            'effective_from' => ['required', 'date'],
            'source_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
