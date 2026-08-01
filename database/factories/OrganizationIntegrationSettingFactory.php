<?php

namespace Database\Factories;

use App\Enums\IntegrationLineDetailMode;
use App\Enums\IntegrationProvider;
use App\Enums\MondayColumnType;
use App\Enums\MondayIntakeLogicalKey;
use App\Models\Organization;
use App\Models\OrganizationIntegrationSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationIntegrationSetting>
 */
class OrganizationIntegrationSettingFactory extends Factory
{
    protected $model = OrganizationIntegrationSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'parent_account_id' => fn (array $attributes): int => (int) Organization::query()
                ->whereKey($attributes['organization_id'])
                ->value('parent_account_id'),
            'provider' => IntegrationProvider::Monday,
            'enabled' => false,
            'api_version' => '2026-07',
            'board_id' => 'fake_board_'.fake()->numerify('######'),
            'group_id' => 'fake_group_'.fake()->numerify('######'),
            'item_name_template' => '{quote_number} — {company_name}',
            'line_detail_mode' => IntegrationLineDetailMode::Summary,
            'column_mapping_json' => self::defaultColumnMapping(),
            'status_label_mappings_json' => [
                'intake_status' => 'New Intake',
            ],
            'lock_version' => 1,
        ];
    }

    /**
     * @return array<string, array{column_id: string, expected_type: string, required: bool, enabled: bool}>
     */
    public static function defaultColumnMapping(): array
    {
        return [
            MondayIntakeLogicalKey::IntegrationKey->value => [
                'column_id' => 'text_integration_key',
                'expected_type' => MondayColumnType::Text->value,
                'required' => true,
                'enabled' => true,
            ],
            MondayIntakeLogicalKey::QuoteNumber->value => [
                'column_id' => 'text_quote_number',
                'expected_type' => MondayColumnType::Text->value,
                'required' => true,
                'enabled' => true,
            ],
            MondayIntakeLogicalKey::CompanyName->value => [
                'column_id' => 'text_company_name',
                'expected_type' => MondayColumnType::Text->value,
                'required' => true,
                'enabled' => true,
            ],
            MondayIntakeLogicalKey::AcceptedDate->value => [
                'column_id' => 'date_accepted',
                'expected_type' => MondayColumnType::Date->value,
                'required' => true,
                'enabled' => true,
            ],
            MondayIntakeLogicalKey::GrandTotal->value => [
                'column_id' => 'numbers_grand_total',
                'expected_type' => MondayColumnType::Numbers->value,
                'required' => true,
                'enabled' => true,
            ],
            MondayIntakeLogicalKey::HalftoneUrl->value => [
                'column_id' => 'link_halftone',
                'expected_type' => MondayColumnType::Link->value,
                'required' => true,
                'enabled' => true,
            ],
            MondayIntakeLogicalKey::IntakeStatus->value => [
                'column_id' => 'status_intake',
                'expected_type' => MondayColumnType::Status->value,
                'required' => true,
                'enabled' => true,
            ],
        ];
    }
}
