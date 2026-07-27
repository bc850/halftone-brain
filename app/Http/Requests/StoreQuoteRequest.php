<?php

namespace App\Http\Requests;

use App\Enums\MembershipStatus;
use App\Models\Quote;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Quote::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organizationId = TenantContext::has() ? TenantContext::get()->organizationId : 0;

        return [
            'sales_owner_membership_id' => [
                'nullable',
                'integer',
                Rule::exists('memberships', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('status', MembershipStatus::Active->value),
            ],
            'primary_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'expiration_date' => ['nullable', 'date'],
            'customer_po_reference' => ['nullable', 'string', 'max:255'],
            'introduction' => ['nullable', 'string', 'max:20000'],
            'terms_text' => ['nullable', 'string', 'max:20000'],
            'customer_notes' => ['nullable', 'string', 'max:20000'],
            'internal_notes' => ['nullable', 'string', 'max:20000'],
        ];
    }
}
