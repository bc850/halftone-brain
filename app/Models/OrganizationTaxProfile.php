<?php

namespace App\Models;

use App\Enums\TaxSourcingStrategy;
use Database\Factories\OrganizationTaxProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One tax configuration per organization. Holds no connector credentials.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property string $default_country
 * @property string|null $default_state
 * @property TaxSourcingStrategy $sourcing_strategy
 * @property string|null $registration_reference
 * @property array<string, mixed>|null $registration_metadata_json
 * @property bool $tax_calculation_enabled
 * @property bool $is_active
 * @property int $configuration_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'default_country',
    'default_state',
    'sourcing_strategy',
    'registration_reference',
    'registration_metadata_json',
    'tax_calculation_enabled',
    'is_active',
    'configuration_version',
])]
class OrganizationTaxProfile extends Model
{
    /** @use HasFactory<OrganizationTaxProfileFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'default_country' => 'US',
        'sourcing_strategy' => 'delivery',
        'tax_calculation_enabled' => true,
        'is_active' => true,
        'configuration_version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sourcing_strategy' => TaxSourcingStrategy::class,
            'registration_metadata_json' => 'array',
            'tax_calculation_enabled' => 'boolean',
            'is_active' => 'boolean',
            'configuration_version' => 'integer',
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
