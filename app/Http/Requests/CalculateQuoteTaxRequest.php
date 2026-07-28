<?php

namespace App\Http\Requests;

use App\Models\Quote;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The jurisdiction is never guessed from an address: picking a configured rate is
 * how the caller confirms which jurisdiction is being applied.
 */
class CalculateQuoteTaxRequest extends FormRequest
{
    public function authorize(): bool
    {
        $quote = $this->route('quote');

        return $quote instanceof Quote && ($this->user()?->can('calculateTax', $quote) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organizationId = TenantContext::has() ? TenantContext::get()->organizationId : 0;

        return [
            'expected_lock_version' => ['required', 'integer', 'min:1'],
            'organization_tax_rate_id' => [
                'required',
                'integer',
                Rule::exists('organization_tax_rates', 'id')->where('organization_id', $organizationId),
            ],
            'certificate_id' => [
                'nullable',
                'integer',
                Rule::exists('organization_company_tax_certificates', 'id')
                    ->where('organization_id', $organizationId),
            ],
        ];
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->validated('expected_lock_version');
    }

    public function organizationTaxRateId(): int
    {
        return (int) $this->validated('organization_tax_rate_id');
    }

    public function certificateId(): ?int
    {
        $certificateId = $this->validated('certificate_id');

        return $certificateId === null ? null : (int) $certificateId;
    }
}
