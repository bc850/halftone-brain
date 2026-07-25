<?php

namespace App\Http\Requests;

use App\Enums\OverheadMode;
use App\Enums\PricingMethod;
use App\Http\Requests\Concerns\NormalizesOrganizationProductPricing;
use App\Models\OrganizationProduct;
use App\Support\Pricing\PricingCalculator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewOrganizationProductPricingRequest extends FormRequest
{
    use NormalizesOrganizationProductPricing;

    public function authorize(): bool
    {
        return $this->user()?->can('previewPricing', OrganizationProduct::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['nullable', 'regex:/^\d+(\.\d{1,'.PricingCalculator::QUANTITY_SCALE.'})?$/'],
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

        $quantity = (string) ($validated['quantity'] ?? '1');
        $normalized = $this->normalizeOrganizationPricing($validated, requireCompletePricing: false);
        $normalized['quantity'] = $quantity === '' ? '1' : $quantity;
        $normalized['pricing_version'] = 1;

        // Preview still runs calculator for response shape; minimum may warn without rejecting.
        return $normalized;
    }
}
