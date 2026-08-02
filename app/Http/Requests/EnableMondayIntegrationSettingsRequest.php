<?php

namespace App\Http\Requests;

use App\Models\OrganizationIntegrationSetting;
use Illuminate\Foundation\Http\FormRequest;

class EnableMondayIntegrationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $settings = $this->route('mondaySetting');

        return $settings instanceof OrganizationIntegrationSetting
            && ($this->user()?->can('enable', $settings) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expected_lock_version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->validated('expected_lock_version');
    }
}
