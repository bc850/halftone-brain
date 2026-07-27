<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesQuoteDraft;
use App\Http\Requests\Concerns\NormalizesQuoteMoney;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuoteCatalogLineRequest extends FormRequest
{
    use AuthorizesQuoteDraft;
    use NormalizesQuoteMoney;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organizationId = TenantContext::has() ? TenantContext::get()->organizationId : 0;

        return [
            'expected_lock_version' => $this->lockVersionRules(),
            'organization_product_id' => [
                'required',
                'integer',
                Rule::exists('organization_products', 'id')->where('organization_id', $organizationId),
            ],
            'quantity' => ['required', ...$this->quantityRules()],
            'override_unit_price' => ['nullable', ...$this->dollarAmountRules()],
            'override_reason' => ['nullable', 'string', 'max:1000', 'required_with:override_unit_price'],
            'is_taxable' => ['nullable', 'boolean'],
            'customer_description' => ['nullable', 'string', 'max:20000'],
            'internal_description' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function overrideUnitPriceCents(): ?int
    {
        return $this->centsFromDollars($this->validated('override_unit_price'), 'override_unit_price');
    }
}
