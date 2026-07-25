<?php

namespace App\Http\Requests;

use App\Enums\UnitOfMeasure;
use App\Models\OrganizationProduct;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewOrganizationProductUnitConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var OrganizationProduct $organizationProduct */
        $organizationProduct = $this->route('organizationProduct');

        return $this->user()?->can('view', $organizationProduct) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from_unit' => ['required', Rule::enum(UnitOfMeasure::class)],
            'to_unit' => ['required', Rule::enum(UnitOfMeasure::class), 'different:from_unit'],
            'numerator' => ['required', 'integer', 'min:1'],
            'denominator' => ['required', 'integer', 'min:1'],
        ];
    }
}
