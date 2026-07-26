<?php

namespace App\Models;

use Database\Factories\OrganizationProductSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Organization link from an OrganizationProduct to a vendor offering.
 *
 * current_package_price_micro_units is the vendor package price for one offering
 * package (Money micro-units). 1C.7A does not copy this into
 * OrganizationProduct.purchase_cost_micro_units.
 *
 * Price semantics (documented for 1C.7C):
 * package $800 for 10 sheets → effective $80/sheet after dividing by
 * package_quantity; preferred-source sync later copies that effective unit cost.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property int $organization_product_id
 * @property int $vendor_product_offering_id
 * @property int|null $current_package_price_micro_units
 * @property string $currency_code
 * @property int $price_version
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'organization_product_id',
    'vendor_product_offering_id',
    'current_package_price_micro_units',
    'currency_code',
    'price_version',
    'is_active',
])]
class OrganizationProductSource extends Model
{
    /** @use HasFactory<OrganizationProductSourceFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency_code' => 'USD',
        'price_version' => 1,
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $source): void {
            $source->assertTenantAlignment();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_package_price_micro_units' => 'integer',
            'price_version' => 'integer',
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

    /**
     * @return BelongsTo<VendorProductOffering, $this>
     */
    public function vendorProductOffering(): BelongsTo
    {
        return $this->belongsTo(VendorProductOffering::class);
    }

    /**
     * @return HasMany<OrganizationProductSourcePriceEvent, $this>
     */
    public function priceEvents(): HasMany
    {
        return $this->hasMany(OrganizationProductSourcePriceEvent::class);
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

        $offering = VendorProductOffering::query()
            ->whereKey($this->vendor_product_offering_id)
            ->first();

        if ($offering === null) {
            throw ValidationException::withMessages([
                'vendor_product_offering_id' => 'The vendor product offering does not exist.',
            ]);
        }

        if (
            $organizationProduct->organization_id !== $this->organization_id
            || $organizationProduct->parent_account_id !== $this->parent_account_id
            || $offering->parent_account_id !== $this->parent_account_id
        ) {
            throw ValidationException::withMessages([
                'organization_id' => 'Source, organization product, and offering must share tenant ownership.',
            ]);
        }

        if ($organizationProduct->product_id !== $offering->product_id) {
            throw ValidationException::withMessages([
                'vendor_product_offering_id' => 'Offering must belong to the same product master as the organization product.',
            ]);
        }
    }
}
