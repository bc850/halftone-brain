<?php

namespace App\Http\Requests;

use App\Models\OrganizationProduct;
use App\Models\OrganizationProductSource;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StoreOrganizationProductSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var OrganizationProduct $organizationProduct */
        $organizationProduct = $this->route('organizationProduct');

        return $this->user()?->can('attach', [OrganizationProductSource::class, $organizationProduct]) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->replace($this->except([
            'parent_account_id',
            'organization_id',
            'organization_product_id',
            'price_version',
            'is_active',
            'preferred_source_id',
        ]));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'vendor_product_offering_id' => ['required', 'integer'],
            'package_price' => ['nullable', 'regex:/^\d+(\.\d{1,'.Money::COST_SCALE.'})?$/'],
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

        $packagePrice = null;
        $raw = $validated['package_price'] ?? null;
        if ($raw !== null && $raw !== '') {
            try {
                $packagePrice = Money::dollarsToMicroUnits((string) $raw);
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'package_price' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'vendor_product_offering_id' => (int) $validated['vendor_product_offering_id'],
            'current_package_price_micro_units' => $packagePrice,
            'note' => $validated['note'] ?? null,
        ];
    }
}
