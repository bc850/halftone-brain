<?php

namespace App\Http\Requests;

use App\Models\OrganizationCompanyTaxCertificate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rejecting or revoking a certificate has to say why: the record of what was
 * relied on, and why it stopped being relied on, is the point of keeping it.
 */
class DecideTaxCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $certificate = $this->route('taxCertificate');

        return $certificate instanceof OrganizationCompanyTaxCertificate
            && ($this->user()?->can('decide', $certificate) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
