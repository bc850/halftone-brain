<?php

namespace App\Http\Requests;

use App\Models\OrganizationProduct;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class UpdateOrganizationProductPurchaseCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var OrganizationProduct $organizationProduct */
        $organizationProduct = $this->route('organizationProduct');

        return $this->user()?->can('updatePurchaseCost', $organizationProduct) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'purchase_cost' => ['nullable', 'regex:/^\d+(\.\d{1,'.Money::COST_SCALE.'})?$/'],
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

        /** @var OrganizationProduct $organizationProduct */
        $organizationProduct = $this->route('organizationProduct');

        $raw = $validated['purchase_cost'] ?? null;
        if ($raw === null || $raw === '') {
            return ['purchase_cost_micro_units' => null];
        }

        if (! $organizationProduct->is_purchasable) {
            throw ValidationException::withMessages([
                'purchase_cost' => 'Purchase cost can only be set on purchasable products.',
            ]);
        }

        if ($organizationProduct->purchase_unit_of_measure === null) {
            throw ValidationException::withMessages([
                'purchase_cost' => 'Purchase unit of measure is required when setting purchase cost.',
            ]);
        }

        return [
            'purchase_cost_micro_units' => Money::dollarsToMicroUnits((string) $raw),
        ];
    }
}
