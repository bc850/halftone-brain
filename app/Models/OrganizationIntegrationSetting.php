<?php

namespace App\Models;

use App\Enums\IntegrationLineDetailMode;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationValidationStatus;
use Database\Factories\OrganizationIntegrationSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-organization external integration configuration.
 *
 * Validation lifecycle fields and lock_version are intentionally excluded from
 * fillable. Future settings services must forceFill guarded transitions and bump
 * lock_version. Never store API tokens or secrets on this model.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property IntegrationProvider $provider
 * @property bool $enabled
 * @property string $api_version
 * @property string|null $board_id
 * @property string|null $group_id
 * @property string $item_name_template
 * @property IntegrationLineDetailMode $line_detail_mode
 * @property array<string, mixed>|null $column_mapping_json
 * @property array<string, mixed>|null $status_label_mappings_json
 * @property Carbon|null $last_validated_at
 * @property IntegrationValidationStatus|null $last_validation_status
 * @property string|null $last_validation_error_code
 * @property string|null $last_validation_error_message
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'provider',
    'enabled',
    'api_version',
    'board_id',
    'group_id',
    'item_name_template',
    'line_detail_mode',
    'column_mapping_json',
    'status_label_mappings_json',
])]
class OrganizationIntegrationSetting extends Model
{
    /** @use HasFactory<OrganizationIntegrationSettingFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'enabled' => false,
        'api_version' => '2026-07',
        'item_name_template' => '{quote_number} — {company_name}',
        'line_detail_mode' => 'summary',
        'lock_version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'enabled' => 'boolean',
            'line_detail_mode' => IntegrationLineDetailMode::class,
            'column_mapping_json' => 'array',
            'status_label_mappings_json' => 'array',
            'last_validated_at' => 'datetime',
            'last_validation_status' => IntegrationValidationStatus::class,
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<ParentAccount, $this>
     */
    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(ParentAccount::class);
    }
}
