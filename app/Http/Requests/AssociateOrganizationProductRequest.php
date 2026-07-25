<?php

namespace App\Http\Requests;

use App\Enums\OverheadMode;
use App\Enums\PricingMethod;
use App\Http\Requests\Concerns\NormalizesOrganizationProductPricing;
use App\Http\Requests\Concerns\ValidatesOrganizationProductClassification;
use App\Models\OrganizationProduct;
use App\Models\Product;
use App\Support\Catalog\ItemClassificationDefaults;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssociateOrganizationProductRequest extends FormRequest
{
    use NormalizesOrganizationProductPricing;
    use ValidatesOrganizationProductClassification;

    public function authorize(): bool
    {
        return $this->user()?->can('associate', OrganizationProduct::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $product = Product::query()->whereKey($this->input('product_id'))->first();

        if ($product === null) {
            return;
        }

        $defaults = ItemClassificationDefaults::for($product->item_kind);
        $merge = [];

        if (! $this->exists('is_sellable')) {
            $merge['is_sellable'] = $defaults['is_sellable'];
        }

        if (! $this->exists('is_purchasable')) {
            $merge['is_purchasable'] = $defaults['is_purchasable'];
        }

        if (! $this->filled('inventory_tracking_mode')) {
            $merge['inventory_tracking_mode'] = $defaults['inventory_tracking_mode'];
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $parentId = TenantContext::get()->parentAccountId;
        $sellable = $this->boolean('is_sellable');
        $includePricing = $this->boolean('include_pricing', false);
        $requiresPricing = $sellable || $includePricing;

        return [
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where(fn ($query) => $query
                    ->where('parent_account_id', $parentId)
                    ->where('is_active', true)),
            ],
            'display_name' => ['nullable', 'string', 'max:255'],
            'is_available' => ['sometimes', 'boolean'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
            'organization_notes' => ['nullable', 'string'],
            ...$this->classificationFieldRules(),
            'include_pricing' => ['sometimes', 'boolean'],
            'material_cost' => [$requiresPricing ? 'required' : 'nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'labor_cost' => [$requiresPricing ? 'required' : 'nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'overhead_mode' => [$requiresPricing ? 'required' : 'nullable', Rule::enum(OverheadMode::class)],
            'overhead_amount' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'overhead_rate_percent' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'pricing_method' => [$requiresPricing ? 'required' : 'nullable', Rule::enum(PricingMethod::class)],
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

        $product = Product::query()->whereKey($validated['product_id'])->firstOrFail();
        $itemKind = $product->item_kind;
        $validated = $this->applyClassificationDefaults($validated, $itemKind);
        $validated = $this->assertClassificationConsistency($validated, $itemKind);

        $includePricing = (bool) ($validated['include_pricing'] ?? false);
        $requiresPricing = $validated['is_sellable'] || $includePricing;

        if (! $requiresPricing) {
            $validated['material_cost_micro_units'] = 0;
            $validated['labor_cost_micro_units'] = 0;
            $validated['overhead_mode'] = OverheadMode::None->value;
            $validated['overhead_amount_micro_units'] = 0;
            $validated['overhead_rate_basis_points'] = 0;
            $validated['pricing_method'] = PricingMethod::Markup->value;
            $validated['markup_basis_points'] = 0;
            $validated['target_margin_basis_points'] = 0;
            $validated['fixed_price_cents'] = null;
            $validated['minimum_price_cents'] = null;
            $validated['allow_price_override'] = false;
            $validated['pricing_complete'] = false;

            return $validated;
        }

        $normalized = $this->normalizeOrganizationPricing($validated, requireCompletePricing: true);
        $normalized['pricing_complete'] = true;

        return $normalized;
    }
}
