<?php

namespace App\Http\Requests;

use App\Enums\TaxSourcingStrategy;
use App\Models\OrganizationTaxProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates or replaces the organization's tax configuration.
 *
 * Creating and updating share one authority because both decide how every future
 * quote is taxed.
 */
class UpdateOrganizationTaxProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', OrganizationTaxProfile::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'default_country' => ['required', 'string', 'size:2'],
            'default_state' => ['nullable', 'string', 'max:10'],
            'sourcing_strategy' => ['required', Rule::enum(TaxSourcingStrategy::class)],
            'registration_reference' => ['nullable', 'string', 'max:255'],
            'tax_calculation_enabled' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array{
     *     default_country: string,
     *     default_state: string|null,
     *     sourcing_strategy: TaxSourcingStrategy,
     *     registration_reference: string|null,
     *     tax_calculation_enabled: bool,
     *     is_active: bool
     * }
     */
    public function profileChanges(): array
    {
        return [
            'default_country' => (string) $this->validated('default_country'),
            'default_state' => $this->validated('default_state'),
            'sourcing_strategy' => TaxSourcingStrategy::from((string) $this->validated('sourcing_strategy')),
            'registration_reference' => $this->validated('registration_reference'),
            'tax_calculation_enabled' => $this->boolean('tax_calculation_enabled'),
            'is_active' => $this->boolean('is_active'),
        ];
    }
}
