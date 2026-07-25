<?php

namespace App\Http\Requests;

use App\Enums\InventoryTrackingMode;
use App\Enums\UnitOfMeasure;
use App\Http\Requests\Concerns\ValidatesOrganizationProductClassification;
use App\Models\OrganizationProduct;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationProductSettingsRequest extends FormRequest
{
    use ValidatesOrganizationProductClassification;

    public function authorize(): bool
    {
        /** @var OrganizationProduct $organizationProduct */
        $organizationProduct = $this->route('organizationProduct');

        return $this->user()?->can('updateSettings', $organizationProduct) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['nullable', 'string', 'max:255'],
            'is_available' => ['required', 'boolean'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'is_sellable' => ['required', 'boolean'],
            'is_purchasable' => ['required', 'boolean'],
            'inventory_tracking_mode' => ['required', Rule::enum(InventoryTrackingMode::class)],
            'purchase_unit_of_measure' => ['nullable', Rule::enum(UnitOfMeasure::class)],
            'stock_unit_of_measure' => ['nullable', Rule::enum(UnitOfMeasure::class)],
            'usage_unit_of_measure' => ['nullable', Rule::enum(UnitOfMeasure::class)],
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
        $organizationProduct->loadMissing('product');
        $itemKind = $organizationProduct->product->item_kind;

        return $this->assertClassificationConsistency($validated, $itemKind);
    }
}
