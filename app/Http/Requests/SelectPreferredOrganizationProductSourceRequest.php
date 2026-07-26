<?php

namespace App\Http\Requests;

use App\Models\OrganizationProductSource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SelectPreferredOrganizationProductSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var OrganizationProductSource $source */
        $source = $this->route('organizationProductSource');

        return $this->user()?->can('selectPreferred', $source) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->replace($this->except([
            'parent_account_id',
            'organization_id',
            'organization_product_id',
        ]));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expected_preferred_source_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated($key, $default);

        if ($key !== null) {
            return $validated;
        }

        $out = [];

        if ($this->exists('expected_preferred_source_id')) {
            $raw = $validated['expected_preferred_source_id'] ?? null;
            $out['expected_preferred_source_id'] = ($raw === null || $raw === '')
                ? null
                : (int) $raw;
        }

        return $out;
    }
}
