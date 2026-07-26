<?php

namespace App\Models;

use Database\Factories\OrganizationProductSourcePriceEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only price history for an organization product source.
 *
 * package_price_micro_units — vendor price for one offering package.
 * effective_purchase_unit_cost_micro_units — normalized cost per OrganizationProduct
 * purchase UOM (e.g. $800 / 10 sheets = $80/sheet).
 *
 * Example: package qty 10 sheets at $800 → effective $80.0000 per sheet
 * (800000 µ package → 80000 µ / sheet when package_quantity_scaled = 10_000_000).
 *
 * No updated_at. 1C.7A does not create events.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property int $organization_product_source_id
 * @property int $package_price_micro_units
 * @property int $effective_purchase_unit_cost_micro_units
 * @property string $currency_code
 * @property int|null $actor_user_id
 * @property string|null $note
 * @property Carbon $recorded_at
 * @property Carbon|null $created_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'organization_product_source_id',
    'package_price_micro_units',
    'effective_purchase_unit_cost_micro_units',
    'currency_code',
    'actor_user_id',
    'note',
    'recorded_at',
])]
class OrganizationProductSourcePriceEvent extends Model
{
    /** @use HasFactory<OrganizationProductSourcePriceEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency_code' => 'USD',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Source price events are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('Source price events are append-only and cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'package_price_micro_units' => 'integer',
            'effective_purchase_unit_cost_micro_units' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ParentAccount, $this>
     */
    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(ParentAccount::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<OrganizationProductSource, $this>
     */
    public function organizationProductSource(): BelongsTo
    {
        return $this->belongsTo(OrganizationProductSource::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
