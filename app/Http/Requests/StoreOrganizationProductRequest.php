<?php

namespace App\Http\Requests;

use App\Enums\OverheadMode;
use App\Enums\PricingMethod;
use App\Enums\ProductFamily;
use App\Enums\UnitOfMeasure;
use App\Http\Requests\Concerns\NormalizesOrganizationProductPricing;
use App\Models\OrganizationProduct;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationProductRequest extends FormRequest
{
    use NormalizesOrganizationProductPricing;

    public function authorize(): bool
    {
        return $this->user()?->can('create', OrganizationProduct::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $parentId = TenantContext::get()->parentAccountId;

        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'required',
                'string',
                'max:100',
                Rule::unique('products', 'sku')->where(fn ($query) => $query->where('parent_account_id', $parentId)),
            ],
            'product_family' => ['required', Rule::enum(ProductFamily::class)],
            'vendor_sku' => ['nullable', 'string', 'max:100'],
            'vendor_id' => [
                'nullable',
                'integer',
                Rule::exists('vendors', 'id')->where(fn ($query) => $query->where('parent_account_id', $parentId)),
            ],
            'product_category_id' => [
                'nullable',
                'integer',
                Rule::exists('product_categories', 'id')->where(fn ($query) => $query->where('parent_account_id', $parentId)),
            ],
            'unit_of_measure' => ['required', Rule::enum(UnitOfMeasure::class)],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'is_available' => ['sometimes', 'boolean'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
            'organization_notes' => ['nullable', 'string'],
            'material_cost' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
            'labor_cost' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
            'overhead_mode' => ['required', Rule::enum(OverheadMode::class)],
            'overhead_amount' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'overhead_rate_percent' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'pricing_method' => ['required', Rule::enum(PricingMethod::class)],
            'markup_percent' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'target_margin_percent' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'fixed_price' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'minimum_price' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'allow_price_override' => ['sometimes', 'boolean'],
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

        return $this->normalizeOrganizationPricing($validated, requireCompletePricing: true);
    }
}
