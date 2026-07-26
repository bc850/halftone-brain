<?php

namespace App\Http\Requests;

use App\Enums\UnitOfMeasure;
use App\Models\VendorProductOffering;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVendorProductOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var VendorProductOffering $offering */
        $offering = $this->route('vendorProductOffering');

        return $this->user()?->can('update', $offering) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('vendor_sku')) {
            $this->merge([
                'vendor_sku' => trim((string) $this->input('vendor_sku')),
            ]);
        }

        $this->request->remove('parent_account_id');
        $this->request->remove('organization_id');
        $this->request->remove('product_id');
        $this->request->remove('vendor_id');
        $this->request->remove('status');
        $this->request->remove('discontinued_at');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $tenant = TenantContext::get();

        /** @var VendorProductOffering $offering */
        $offering = $this->route('vendorProductOffering');

        return [
            'vendor_sku' => [
                'required',
                'string',
                'max:255',
                Rule::unique('vendor_product_offerings', 'vendor_sku')
                    ->ignore($offering->id)
                    ->where(
                        fn ($query) => $query
                            ->where('parent_account_id', $tenant->parentAccountId)
                            ->where('vendor_id', $offering->vendor_id),
                    ),
            ],
            'vendor_description' => ['nullable', 'string', 'max:5000'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'manufacturer_part_number' => ['nullable', 'string', 'max:255'],
            'product_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'purchase_uom' => ['required', Rule::enum(UnitOfMeasure::class)],
            'package_quantity' => [
                'required',
                'regex:/^\d+(\.\d{1,'.ComponentCostEstimator::QUANTITY_SCALE.'})?$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_numeric($value)) {
                        $fail('Package quantity must be a decimal quantity string.');

                        return;
                    }

                    try {
                        if (ComponentCostEstimator::quantityToScaled((string) $value) < 1) {
                            $fail('Package quantity must be greater than zero.');
                        }
                    } catch (\Throwable) {
                        $fail('Package quantity must be greater than zero.');
                    }
                },
            ],
            'minimum_order_quantity' => [
                'nullable',
                'regex:/^\d+(\.\d{1,'.ComponentCostEstimator::QUANTITY_SCALE.'})?$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    if (! is_numeric($value)) {
                        $fail('Minimum order quantity must be a decimal quantity string.');

                        return;
                    }

                    try {
                        if (ComponentCostEstimator::quantityToScaled((string) $value) < 1) {
                            $fail('Minimum order quantity must be greater than zero when set.');
                        }
                    } catch (\Throwable) {
                        $fail('Minimum order quantity must be greater than zero when set.');
                    }
                },
            ],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
