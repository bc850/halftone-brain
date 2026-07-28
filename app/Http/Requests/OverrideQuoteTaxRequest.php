<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesQuoteMoney;
use App\Models\Quote;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A manual tax figure is a narrower authority than calculating one, and it always
 * carries the reason no configured rate could produce it.
 */
class OverrideQuoteTaxRequest extends FormRequest
{
    use NormalizesQuoteMoney;

    public function authorize(): bool
    {
        $quote = $this->route('quote');

        return $quote instanceof Quote && ($this->user()?->can('overrideTax', $quote) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organizationId = TenantContext::has() ? TenantContext::get()->organizationId : 0;

        return [
            'expected_lock_version' => ['required', 'integer', 'min:1'],
            'override_tax' => ['required', ...$this->dollarAmountRules()],
            'reason' => ['required', 'string', 'max:1000'],
            'organization_tax_rate_id' => [
                'nullable',
                'integer',
                Rule::exists('organization_tax_rates', 'id')->where('organization_id', $organizationId),
            ],
        ];
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->validated('expected_lock_version');
    }

    public function overrideTax(): string
    {
        return trim((string) $this->validated('override_tax'));
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }

    public function organizationTaxRateId(): ?int
    {
        $rateId = $this->validated('organization_tax_rate_id');

        return $rateId === null ? null : (int) $rateId;
    }
}
