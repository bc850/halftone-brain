<?php

namespace App\Http\Requests\Concerns;

use App\Enums\IntegrationLineDetailMode;
use App\Enums\MondayColumnType;
use App\Enums\MondayIntakeLogicalKey;
use App\Support\Integrations\Monday\MondayApiVersion;
use App\Support\Integrations\Monday\MondayOrganizationSettingsService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesMondaySettingsPayload
{
    /**
     * @return array<string, mixed>
     */
    protected function mondaySettingsRules(bool $requireLockVersion): array
    {
        $columnTypes = array_column(MondayColumnType::cases(), 'value');

        $rules = [
            'board_id' => ['required', 'string', 'max:64'],
            'group_id' => ['required', 'string', 'max:64'],
            'api_version' => ['required', 'string', Rule::in([MondayApiVersion::PINNED])],
            'item_name_template' => ['required', 'string', 'max:191'],
            'line_detail_mode' => ['required', 'string', Rule::enum(IntegrationLineDetailMode::class)],
            'intake_status_label' => ['required', 'string', 'max:64'],
            'required_mappings' => ['required', 'array'],
            'optional_mappings' => ['sometimes', 'array'],
            'parent_account_id' => ['prohibited'],
            'organization_id' => ['prohibited'],
            'provider' => ['prohibited'],
            'enabled' => ['prohibited'],
            'column_mapping_json' => ['prohibited'],
            'status_label_mappings_json' => ['prohibited'],
            'api_token' => ['prohibited'],
            'access_token' => ['prohibited'],
            'refresh_token' => ['prohibited'],
            'authorization' => ['prohibited'],
            'client_secret' => ['prohibited'],
            'token' => ['prohibited'],
            'last_validated_at' => ['prohibited'],
            'last_validation_status' => ['prohibited'],
            'last_validation_error_code' => ['prohibited'],
            'last_validation_error_message' => ['prohibited'],
            'lock_version' => ['prohibited'],
            'requested_due_date' => ['prohibited'],
            'expiration_date' => ['prohibited'],
        ];

        foreach (MondayIntakeLogicalKey::requiredForActivation() as $key) {
            $rules['required_mappings.'.$key->value] = ['required', 'array'];
            $rules['required_mappings.'.$key->value.'.column_id'] = ['required', 'string', 'max:64'];
            $rules['required_mappings.'.$key->value.'.expected_type'] = ['required', 'string', Rule::in($columnTypes)];
        }

        foreach ([
            MondayIntakeLogicalKey::Organization,
            MondayIntakeLogicalKey::RevisionNumber,
            MondayIntakeLogicalKey::PrimaryContact,
            MondayIntakeLogicalKey::Salesperson,
            MondayIntakeLogicalKey::PretaxTotal,
            MondayIntakeLogicalKey::TaxTotal,
            MondayIntakeLogicalKey::LineSummary,
        ] as $key) {
            $rules['optional_mappings.'.$key->value] = ['sometimes', 'array'];
            $rules['optional_mappings.'.$key->value.'.enabled'] = ['sometimes', 'boolean'];
            $rules['optional_mappings.'.$key->value.'.column_id'] = ['nullable', 'string', 'max:64'];
            $rules['optional_mappings.'.$key->value.'.expected_type'] = ['nullable', 'string', Rule::in($columnTypes)];
        }

        if ($requireLockVersion) {
            $rules['expected_lock_version'] = ['required', 'integer', 'min:1'];
        }

        return $rules;
    }

    protected function withMondaySettingsValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                MondayOrganizationSettingsService::buildMappingFromFormFields(
                    $this->input('required_mappings', []),
                    $this->input('optional_mappings', []),
                );
            } catch (\InvalidArgumentException $exception) {
                $validator->errors()->add('required_mappings', $exception->getMessage());
            }
        });
    }

    /**
     * @return array{
     *     board_id: string,
     *     group_id: string,
     *     api_version: string,
     *     item_name_template: string,
     *     line_detail_mode: string,
     *     column_mapping_json: array<string, array{column_id: string, expected_type: string, required: bool, enabled: bool}>,
     *     status_label_mappings_json: array{intake_status: string},
     *     expected_lock_version?: int
     * }
     */
    public function mondaySettingsInput(): array
    {
        /** @var array<string, array{column_id: string, expected_type: string}> $required */
        $required = $this->validated('required_mappings');
        /** @var array<string, array{enabled?: bool, column_id?: string|null, expected_type?: string|null}> $optional */
        $optional = $this->validated('optional_mappings') ?? [];

        $input = [
            'board_id' => (string) $this->validated('board_id'),
            'group_id' => (string) $this->validated('group_id'),
            'api_version' => (string) $this->validated('api_version'),
            'item_name_template' => (string) $this->validated('item_name_template'),
            'line_detail_mode' => (string) $this->validated('line_detail_mode'),
            'column_mapping_json' => MondayOrganizationSettingsService::buildMappingFromFormFields($required, $optional),
            'status_label_mappings_json' => [
                'intake_status' => (string) $this->validated('intake_status_label'),
            ],
        ];

        if ($this->has('expected_lock_version')) {
            $input['expected_lock_version'] = (int) $this->validated('expected_lock_version');
        }

        return $input;
    }
}
