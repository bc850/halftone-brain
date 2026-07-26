<?php

namespace App\Http\Requests;

use App\Enums\UnitOfMeasure;
use App\Http\Requests\Concerns\NormalizesProductMoney;
use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    use NormalizesProductMoney;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('vendor_id');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:100', 'unique:products,sku'],
            'vendor_sku' => ['nullable', 'string', 'max:100'],
            'product_category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'unit_of_measure' => ['required', Rule::enum(UnitOfMeasure::class)],
            'true_cost' => ['required', 'numeric', 'min:0'],
            'markup_percent' => ['required', 'numeric', 'min:0'],
            'list_price' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'related_product_ids' => ['nullable', 'array'],
            'related_product_ids.*' => ['integer', 'exists:products,id'],
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

        return $this->normalizeProductMoney($validated);
    }
}
