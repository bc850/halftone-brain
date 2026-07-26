<?php

namespace App\Http\Requests;

use App\Enums\ItemKind;
use App\Enums\ProductFamily;
use App\Enums\UnitOfMeasure;
use App\Models\OrganizationProduct;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var OrganizationProduct $organizationProduct */
        $organizationProduct = $this->route('organizationProduct');

        return $this->user()?->can('updateMaster', $organizationProduct) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('vendor_id');
        $this->request->remove('vendor_sku');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $parentId = TenantContext::get()->parentAccountId;
        /** @var OrganizationProduct $organizationProduct */
        $organizationProduct = $this->route('organizationProduct');
        $productId = $organizationProduct->product_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'required',
                'string',
                'max:100',
                Rule::unique('products', 'sku')
                    ->where(fn ($query) => $query->where('parent_account_id', $parentId))
                    ->ignore($productId),
            ],
            'product_family' => ['required', Rule::enum(ProductFamily::class)],
            'item_kind' => ['required', Rule::enum(ItemKind::class)],
            'product_category_id' => [
                'nullable',
                'integer',
                Rule::exists('product_categories', 'id')->where(fn ($query) => $query->where('parent_account_id', $parentId)),
            ],
            'unit_of_measure' => ['required', Rule::enum(UnitOfMeasure::class)],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
