<?php

namespace App\Http\Requests;

use App\Enums\ItemKind;
use App\Enums\OverheadMode;
use App\Enums\PricingMethod;
use App\Enums\ProductFamily;
use App\Enums\UnitOfMeasure;
use App\Http\Requests\Concerns\NormalizesOrganizationProductPricing;
use App\Http\Requests\Concerns\ValidatesOrganizationProductClassification;
use App\Models\OrganizationProduct;
use App\Support\Catalog\ItemClassificationDefaults;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationProductRequest extends FormRequest
{
    use NormalizesOrganizationProductPricing;
    use ValidatesOrganizationProductClassification;

    public function authorize(): bool
    {
        return $this->user()?->can('create', OrganizationProduct::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('vendor_id');
        $this->request->remove('vendor_sku');

        if (! $this->filled('item_kind')) {
            return;
        }

        $itemKind = ItemKind::tryFrom((string) $this->input('item_kind'));

        if ($itemKind === null) {
            return;
        }

        $defaults = ItemClassificationDefaults::for($itemKind);
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

        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'required',
                'string',
                'max:100',
                Rule::unique('products', 'sku')->where(fn ($query) => $query->where('parent_account_id', $parentId)),
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
            'display_name' => ['nullable', 'string', 'max:255'],
            'is_available' => ['sometimes', 'boolean'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
            'organization_notes' => ['nullable', 'string'],
            ...$this->classificationFieldRules(),
            'material_cost' => [$sellable ? 'required' : 'nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'labor_cost' => [$sellable ? 'required' : 'nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'overhead_mode' => [$sellable ? 'required' : 'nullable', Rule::enum(OverheadMode::class)],
            'overhead_amount' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'overhead_rate_percent' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'pricing_method' => [$sellable ? 'required' : 'nullable', Rule::enum(PricingMethod::class)],
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

        $itemKind = ItemKind::from((string) $validated['item_kind']);
        $validated = $this->applyClassificationDefaults($validated, $itemKind);
        $validated = $this->assertClassificationConsistency($validated, $itemKind);

        if (! $validated['is_sellable']) {
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

            foreach ([
                'material_cost', 'labor_cost', 'overhead_amount', 'overhead_rate_percent',
                'markup_percent', 'target_margin_percent', 'fixed_price', 'minimum_price',
            ] as $field) {
                unset($validated[$field]);
            }

            return $validated;
        }

        return $this->normalizeOrganizationPricing($validated, requireCompletePricing: true);
    }
}
