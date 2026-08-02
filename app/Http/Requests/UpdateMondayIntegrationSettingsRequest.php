<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesMondaySettingsPayload;
use App\Models\OrganizationIntegrationSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateMondayIntegrationSettingsRequest extends FormRequest
{
    use ValidatesMondaySettingsPayload;

    public function authorize(): bool
    {
        $settings = $this->route('mondaySetting');

        return $settings instanceof OrganizationIntegrationSetting
            && ($this->user()?->can('update', $settings) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->mondaySettingsRules(requireLockVersion: true);
    }

    public function withValidator(Validator $validator): void
    {
        $this->withMondaySettingsValidator($validator);
    }
}
