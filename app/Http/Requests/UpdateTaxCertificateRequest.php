<?php

namespace App\Http\Requests;

use App\Enums\TaxExemptionCategory;
use App\Models\OrganizationCompanyTaxCertificate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A certificate is editable only while pending; the service enforces that, so this
 * request only decides which fields may be offered at all.
 */
class UpdateTaxCertificateRequest extends FormRequest
{
    /**
     * @var list<string>
     */
    private const EDITABLE_FIELDS = [
        'exemption_category',
        'jurisdiction_state',
        'certificate_form_type',
        'certificate_number',
        'evidence_reference',
        'effective_date',
        'expiration_date',
        'internal_notes',
    ];

    public function authorize(): bool
    {
        $certificate = $this->route('taxCertificate');

        return $certificate instanceof OrganizationCompanyTaxCertificate
            && ($this->user()?->can('update', $certificate) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'exemption_category' => ['sometimes', 'required', Rule::enum(TaxExemptionCategory::class)],
            'jurisdiction_state' => ['sometimes', 'required', 'string', 'max:10'],
            'certificate_form_type' => ['sometimes', 'required', 'string', 'max:100'],
            'certificate_number' => ['sometimes', 'nullable', 'string', 'max:190'],
            'evidence_reference' => ['sometimes', 'nullable', 'string', 'max:500'],
            'effective_date' => ['sometimes', 'required', 'date'],
            'expiration_date' => ['sometimes', 'nullable', 'date'],
            'internal_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function certificateChanges(): array
    {
        return array_intersect_key($this->validated(), array_flip(self::EDITABLE_FIELDS));
    }
}
