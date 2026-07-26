<?php

namespace App\Http\Requests;

use App\Enums\UnitOfMeasure;
use App\Models\OrganizationProduct;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationProductComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var OrganizationProduct $organizationProduct */
        $organizationProduct = $this->route('organizationProduct');

        return $this->user()?->can('manageComponents', $organizationProduct) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'components_version' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'regex:/^\d+(\.\d{1,'.ComponentCostEstimator::QUANTITY_SCALE.'})?$/'],
            'usage_uom' => ['required', Rule::enum(UnitOfMeasure::class)],
            'waste_basis_points' => ['sometimes', 'integer', 'min:0', 'max:'.ComponentCostEstimator::MAX_WASTE_BASIS_POINTS],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
