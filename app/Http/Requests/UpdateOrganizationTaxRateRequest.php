<?php

namespace App\Http\Requests;

use App\Models\OrganizationTaxRate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Only labelling, provenance, and the closing date are editable. Changing what a
 * rate charges means superseding it so the old figure stays explainable.
 */
class UpdateOrganizationTaxRateRequest extends FormRequest
{
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
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            'source_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'effective_through' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rateChanges(): array
    {
        return array_intersect_key(
            $this->validated(),
            array_flip(['display_name', 'source_note', 'effective_through']),
        );
    }
}
