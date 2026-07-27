<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesQuoteDraft;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateQuotePartySnapshotRequest extends FormRequest
{
    use AuthorizesQuoteDraft;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expected_lock_version' => $this->lockVersionRules(),
            'primary_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:64'],
            'billing_address_json' => ['nullable', 'array'],
            'billing_address_json.line1' => ['nullable', 'string', 'max:255'],
            'billing_address_json.line2' => ['nullable', 'string', 'max:255'],
            'billing_address_json.city' => ['nullable', 'string', 'max:255'],
            'billing_address_json.state' => ['nullable', 'string', 'max:64'],
            'billing_address_json.postal_code' => ['nullable', 'string', 'max:32'],
            'billing_address_json.country' => ['nullable', 'string', 'max:64'],
            'service_address_json' => ['nullable', 'array'],
            'service_address_json.line1' => ['nullable', 'string', 'max:255'],
            'service_address_json.line2' => ['nullable', 'string', 'max:255'],
            'service_address_json.city' => ['nullable', 'string', 'max:255'],
            'service_address_json.state' => ['nullable', 'string', 'max:64'],
            'service_address_json.postal_code' => ['nullable', 'string', 'max:32'],
            'service_address_json.country' => ['nullable', 'string', 'max:64'],
            'customer_po_reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotChanges(): array
    {
        $fields = [
            'primary_contact_id',
            'contact_name',
            'contact_email',
            'contact_phone',
            'billing_address_json',
            'service_address_json',
            'customer_po_reference',
        ];

        $validated = $this->validated();
        $changes = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = $validated[$field];
            }
        }

        return $changes;
    }
}
