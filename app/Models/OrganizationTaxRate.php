<?php

namespace App\Models;

use Database\Factories\OrganizationTaxRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An organization-configured jurisdiction and its rate for an effective period.
 *
 * `rate_ppm` is parts per million, so 8% is 80,000 ppm. No rate is ever stored
 * as a float, and no rate is shipped as a default: every row is entered by the
 * organization that will be taxed under it.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property string $country
 * @property string|null $state
 * @property string|null $county
 * @property string|null $city
 * @property string|null $postal_code
 * @property array<string, mixed>|null $routing_metadata_json
 * @property string $jurisdiction_code
 * @property string $display_name
 * @property int $rate_ppm
 * @property Carbon $effective_from
 * @property Carbon|null $effective_through
 * @property bool $is_active
 * @property string|null $source_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'country',
    'state',
    'county',
    'city',
    'postal_code',
    'routing_metadata_json',
    'jurisdiction_code',
    'display_name',
    'rate_ppm',
    'effective_from',
    'effective_through',
    'is_active',
    'source_note',
])]
class OrganizationTaxRate extends Model
{
    /** @use HasFactory<OrganizationTaxRateFactory> */
    use HasFactory;

    public const RATE_PPM_DENOMINATOR = 1_000_000;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'country' => 'US',
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'routing_metadata_json' => 'array',
            'rate_ppm' => 'integer',
            'effective_from' => 'date',
            'effective_through' => 'date',
            'is_active' => 'boolean',
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

    /**
     * Safe subset for snapshots and customer-facing display.
     *
     * @return array{jurisdiction_code: string, display_name: string, country: string, state: string|null, county: string|null, city: string|null, rate_ppm: int, effective_from: string, effective_through: string|null}
     */
    public function toJurisdictionSnapshot(): array
    {
        return [
            'jurisdiction_code' => $this->jurisdiction_code,
            'display_name' => $this->display_name,
            'country' => $this->country,
            'state' => $this->state,
            'county' => $this->county,
            'city' => $this->city,
            'rate_ppm' => $this->rate_ppm,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_through' => $this->effective_through?->toDateString(),
        ];
    }
}
