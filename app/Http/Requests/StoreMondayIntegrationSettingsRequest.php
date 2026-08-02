<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesMondaySettingsPayload;
use App\Models\OrganizationIntegrationSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreMondayIntegrationSettingsRequest extends FormRequest
{
    use ValidatesMondaySettingsPayload;

    public function authorize(): bool
    {
        return $this->user()?->can('create', OrganizationIntegrationSetting::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->mondaySettingsRules(requireLockVersion: false);
    }

    public function withValidator(Validator $validator): void
    {
        $this->withMondaySettingsValidator($validator);
    }
}
