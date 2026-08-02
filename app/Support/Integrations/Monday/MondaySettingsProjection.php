<?php

namespace App\Support\Integrations\Monday;

use App\Enums\IntegrationLineDetailMode;
use App\Enums\IntegrationValidationStatus;
use App\Enums\MondayColumnType;
use App\Enums\MondayIntakeLogicalKey;
use App\Models\OrganizationIntegrationSetting;
use App\Support\Integrations\Outbox\IntegrationErrorSanitizer;

final class MondaySettingsProjection
{
    public function __construct(
        private IntegrationErrorSanitizer $sanitizer,
    ) {}

    /**
     * @return array{
     *     settings: array<string, mixed>|null,
     *     defaults: array<string, mixed>,
     *     column_types: list<array{value: string, label: string}>,
     *     line_detail_modes: list<array{value: string, label: string}>,
     *     required_mapping_keys: list<array{key: string, label: string}>,
     *     optional_mapping_keys: list<array{key: string, label: string}>,
     *     allowed_template_placeholders: list<string>,
     *     pinned_api_version: string,
     *     can_manage: bool,
     *     can_validate: bool,
     *     safety_notes: list<string>,
     *     explanation: list<string>
     * }
     */
    public function page(
        ?OrganizationIntegrationSetting $settings,
        bool $canManage,
        bool $canValidate,
    ): array {
        return [
            'settings' => $settings === null ? null : $this->settings($settings),
            'defaults' => [
                'api_version' => MondayApiVersion::PINNED,
                'item_name_template' => MondayItemNameTemplate::DEFAULT,
                'line_detail_mode' => IntegrationLineDetailMode::Summary->value,
                'intake_status_label' => 'New Intake',
            ],
            'column_types' => array_map(
                static fn (MondayColumnType $type): array => [
                    'value' => $type->value,
                    'label' => str_replace('_', ' ', ucfirst($type->value)),
                ],
                MondayColumnType::cases(),
            ),
            'line_detail_modes' => [
                ['value' => IntegrationLineDetailMode::Summary->value, 'label' => 'Summary'],
                ['value' => IntegrationLineDetailMode::None->value, 'label' => 'None'],
            ],
            'required_mapping_keys' => $this->labels(MondayIntakeLogicalKey::requiredForActivation()),
            'optional_mapping_keys' => $this->labels([
                MondayIntakeLogicalKey::Organization,
                MondayIntakeLogicalKey::RevisionNumber,
                MondayIntakeLogicalKey::PrimaryContact,
                MondayIntakeLogicalKey::Salesperson,
                MondayIntakeLogicalKey::PretaxTotal,
                MondayIntakeLogicalKey::TaxTotal,
                MondayIntakeLogicalKey::LineSummary,
            ]),
            'allowed_template_placeholders' => MondayItemNameTemplate::allowedPlaceholders(),
            'pinned_api_version' => MondayApiVersion::PINNED,
            'can_manage' => $canManage,
            'can_validate' => $canValidate,
            'safety_notes' => [
                'Costs, margins, and markup are never sent to Monday.',
                'Approval reasoning and internal notes stay in Halftone Brain.',
                'Tax-certificate evidence and private document paths are excluded.',
                'Customer access tokens, response IP, and credentials are never stored or displayed here.',
                'Quote expiration is not a production due date and is not sent to Monday.',
            ],
            'explanation' => [
                'Halftone Brain remains the source of truth for quotes and customers.',
                'Monday is an optional intake destination for accepted quotes.',
                'Nothing is sent until configuration is validated, enabled, the Monday consumer is activated, and processing is separately turned on.',
                'API tokens are never entered or displayed on this page.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(OrganizationIntegrationSetting $settings): array
    {
        $mapping = $settings->column_mapping_json ?? [];
        $required = [];
        $optional = [];

        foreach (MondayIntakeLogicalKey::requiredForActivation() as $key) {
            $entry = is_array($mapping[$key->value] ?? null) ? $mapping[$key->value] : [];
            $required[$key->value] = [
                'column_id' => (string) ($entry['column_id'] ?? ''),
                'expected_type' => (string) ($entry['expected_type'] ?? ''),
            ];
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
            $entry = is_array($mapping[$key->value] ?? null) ? $mapping[$key->value] : null;
            $optional[$key->value] = [
                'enabled' => is_array($entry) && (bool) ($entry['enabled'] ?? false),
                'column_id' => is_array($entry) ? (string) ($entry['column_id'] ?? '') : '',
                'expected_type' => is_array($entry) ? (string) ($entry['expected_type'] ?? '') : '',
            ];
        }

        $status = $settings->last_validation_status;

        return [
            'id' => $settings->id,
            'enabled' => $settings->enabled,
            'api_version' => $settings->api_version,
            'board_id' => $settings->board_id,
            'group_id' => $settings->group_id,
            'item_name_template' => $settings->item_name_template,
            'line_detail_mode' => $settings->line_detail_mode->value,
            'required_mappings' => $required,
            'optional_mappings' => $optional,
            'intake_status_label' => (string) (($settings->status_label_mappings_json ?? [])['intake_status'] ?? ''),
            'last_validated_at' => $settings->last_validated_at?->toIso8601String(),
            'last_validation_status' => ($status ?? IntegrationValidationStatus::NeverValidated)->value,
            'last_validation_error_code' => $this->sanitizer->code($settings->last_validation_error_code),
            'last_validation_error_message' => $this->sanitizer->message($settings->last_validation_error_message),
            'lock_version' => $settings->lock_version,
            'can_enable' => $status === IntegrationValidationStatus::Valid
                && $settings->last_validated_at !== null
                && ! $settings->enabled
                && $settings->last_validation_error_code === null,
        ];
    }

    /**
     * @param  list<MondayIntakeLogicalKey>  $keys
     * @return list<array{key: string, label: string}>
     */
    private function labels(array $keys): array
    {
        $labels = [
            MondayIntakeLogicalKey::IntegrationKey->value => 'Halftone Brain integration key',
            MondayIntakeLogicalKey::QuoteNumber->value => 'Quote number',
            MondayIntakeLogicalKey::CompanyName->value => 'Company name',
            MondayIntakeLogicalKey::AcceptedDate->value => 'Accepted date',
            MondayIntakeLogicalKey::GrandTotal->value => 'Grand total',
            MondayIntakeLogicalKey::HalftoneUrl->value => 'Halftone Brain URL',
            MondayIntakeLogicalKey::IntakeStatus->value => 'Intake status',
            MondayIntakeLogicalKey::Organization->value => 'Organization',
            MondayIntakeLogicalKey::RevisionNumber->value => 'Revision number',
            MondayIntakeLogicalKey::PrimaryContact->value => 'Primary contact',
            MondayIntakeLogicalKey::Salesperson->value => 'Salesperson',
            MondayIntakeLogicalKey::PretaxTotal->value => 'Pretax total',
            MondayIntakeLogicalKey::TaxTotal->value => 'Tax total',
            MondayIntakeLogicalKey::LineSummary->value => 'Line summary',
        ];

        return array_map(
            static fn (MondayIntakeLogicalKey $key): array => [
                'key' => $key->value,
                'label' => $labels[$key->value],
            ],
            $keys,
        );
    }
}
