<?php

namespace App\Models;

use App\Enums\UnitOfMeasure;
use App\Support\Catalog\UnitConversion as UnitConversionValue;
use Database\Factories\OrganizationProductUnitConversionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Exact organization-scoped unit conversion for an OrganizationProduct.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property int $organization_product_id
 * @property UnitOfMeasure $from_unit
 * @property UnitOfMeasure $to_unit
 * @property int $numerator
 * @property int $denominator
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'organization_product_id',
    'from_unit',
    'to_unit',
    'numerator',
    'denominator',
    'is_active',
])]
class OrganizationProductUnitConversion extends Model
{
    /** @use HasFactory<OrganizationProductUnitConversionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $conversion): void {
            $conversion->assertValidRatio();
            $conversion->assertTenantAlignment();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_unit' => UnitOfMeasure::class,
            'to_unit' => UnitOfMeasure::class,
            'numerator' => 'integer',
            'denominator' => 'integer',
            'is_active' => 'boolean',
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
     * @return BelongsTo<OrganizationProduct, $this>
     */
    public function organizationProduct(): BelongsTo
    {
        return $this->belongsTo(OrganizationProduct::class);
    }

    public function toValueObject(): UnitConversionValue
    {
        return new UnitConversionValue(
            $this->from_unit,
            $this->to_unit,
            $this->numerator,
            $this->denominator,
        );
    }

    private function assertValidRatio(): void
    {
        if ($this->numerator < 1) {
            throw ValidationException::withMessages([
                'numerator' => 'Conversion numerator must be greater than zero.',
            ]);
        }

        if ($this->denominator < 1) {
            throw ValidationException::withMessages([
                'denominator' => 'Conversion denominator must be greater than zero.',
            ]);
        }
    }

    private function assertTenantAlignment(): void
    {
        $organizationProduct = OrganizationProduct::query()
            ->whereKey($this->organization_product_id)
            ->first();

        if ($organizationProduct === null) {
            throw ValidationException::withMessages([
                'organization_product_id' => 'The organization product does not exist.',
            ]);
        }

        if ($organizationProduct->organization_id !== $this->organization_id) {
            throw ValidationException::withMessages([
                'organization_id' => 'The conversion organization must match the organization product.',
            ]);
        }

        if ($organizationProduct->parent_account_id !== $this->parent_account_id) {
            throw ValidationException::withMessages([
                'parent_account_id' => 'The conversion parent account must match the organization product.',
            ]);
        }
    }
}
