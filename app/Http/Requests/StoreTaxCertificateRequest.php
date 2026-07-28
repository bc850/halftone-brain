<?php

namespace App\Http\Requests;

use App\Enums\TaxExemptionCategory;
use App\Models\OrganizationCompanyTaxCertificate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaxCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', OrganizationCompanyTaxCertificate::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'exemption_category' => ['required', Rule::enum(TaxExemptionCategory::class)],
            'jurisdiction_state' => ['required', 'string', 'max:10'],
            'certificate_form_type' => ['required', 'string', 'max:100'],
            'certificate_number' => ['nullable', 'string', 'max:190'],
            'evidence_reference' => ['nullable', 'string', 'max:500'],
            'effective_date' => ['required', 'date'],
            'expiration_date' => ['nullable', 'date', 'after_or_equal:effective_date'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function exemptionCategory(): TaxExemptionCategory
    {
        return TaxExemptionCategory::from((string) $this->validated('exemption_category'));
    }
}
