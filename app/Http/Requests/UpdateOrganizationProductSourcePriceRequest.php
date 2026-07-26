<?php

namespace App\Http\Requests;

use App\Models\OrganizationProductSource;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class UpdateOrganizationProductSourcePriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var OrganizationProductSource $source */
        $source = $this->route('organizationProductSource');

        return $this->user()?->can('updatePrice', $source) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->replace($this->except([
            'parent_account_id',
            'organization_id',
            'organization_product_id',
            'vendor_product_offering_id',
            'is_active',
            'preferred_source_id',
            'currency_code',
        ]));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'package_price' => ['required', 'regex:/^\d+(\.\d{1,'.Money::COST_SCALE.'})?$/'],
            'expected_price_version' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
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

        try {
            $packagePrice = Money::dollarsToMicroUnits((string) $validated['package_price']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'package_price' => $exception->getMessage(),
            ]);
        }

        return [
            'current_package_price_micro_units' => $packagePrice,
            'expected_price_version' => (int) $validated['expected_price_version'],
            'note' => $validated['note'] ?? null,
        ];
    }
}
