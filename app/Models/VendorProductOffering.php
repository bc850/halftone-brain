<?php

namespace App\Models;

use App\Enums\UnitOfMeasure;
use App\Enums\VendorProductOfferingStatus;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use Database\Factories\VendorProductOfferingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Parent-scoped vendor catalog offering for a Product Master.
 *
 * Does not store vendor price.
 * Legacy products.vendor_id has been retired; use offerings for vendor links.
 *
 * Package quantity uses six-decimal scaled integers
 * ({@see ComponentCostEstimator::QUANTITY_SCALE_FACTOR}).
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $product_id
 * @property int $vendor_id
 * @property string $vendor_sku
 * @property string|null $vendor_description
 * @property string|null $manufacturer
 * @property string|null $manufacturer_part_number
 * @property string|null $product_url
 * @property UnitOfMeasure $purchase_uom
 * @property int $package_quantity_scaled
 * @property int|null $minimum_order_quantity_scaled
 * @property int|null $lead_time_days
 * @property VendorProductOfferingStatus $status
 * @property Carbon|null $discontinued_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parent_account_id',
    'product_id',
    'vendor_id',
    'vendor_sku',
    'vendor_description',
    'manufacturer',
    'manufacturer_part_number',
    'product_url',
    'purchase_uom',
    'package_quantity_scaled',
    'minimum_order_quantity_scaled',
    'lead_time_days',
    'status',
    'discontinued_at',
])]
class VendorProductOffering extends Model
{
    /** @use HasFactory<VendorProductOfferingFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'package_quantity_scaled' => ComponentCostEstimator::QUANTITY_SCALE_FACTOR,
        'status' => 'active',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $offering): void {
            $offering->normalizeVendorSku();
            $offering->assertPackageQuantity();
            $offering->assertTenantAlignment();
            $offering->syncDiscontinuedTimestamp();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purchase_uom' => UnitOfMeasure::class,
            'package_quantity_scaled' => 'integer',
            'minimum_order_quantity_scaled' => 'integer',
            'lead_time_days' => 'integer',
            'status' => VendorProductOfferingStatus::class,
            'discontinued_at' => 'datetime',
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
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return HasMany<OrganizationProductSource, $this>
     */
    public function organizationSources(): HasMany
    {
        return $this->hasMany(OrganizationProductSource::class);
    }

    private function normalizeVendorSku(): void
    {
        $sku = trim((string) $this->vendor_sku);

        if ($sku === '') {
            throw ValidationException::withMessages([
                'vendor_sku' => 'Vendor SKU is required.',
            ]);
        }

        $this->vendor_sku = $sku;
    }

    private function assertPackageQuantity(): void
    {
        if ($this->package_quantity_scaled < 1) {
            throw ValidationException::withMessages([
                'package_quantity_scaled' => 'Package quantity must be greater than zero.',
            ]);
        }

        if (
            $this->minimum_order_quantity_scaled !== null
            && $this->minimum_order_quantity_scaled < 1
        ) {
            throw ValidationException::withMessages([
                'minimum_order_quantity_scaled' => 'Minimum order quantity must be greater than zero when set.',
            ]);
        }
    }

    private function assertTenantAlignment(): void
    {
        $product = Product::query()->whereKey($this->product_id)->first();
        if ($product === null) {
            throw ValidationException::withMessages([
                'product_id' => 'The product master does not exist.',
            ]);
        }

        $vendor = Vendor::query()->whereKey($this->vendor_id)->first();
        if ($vendor === null) {
            throw ValidationException::withMessages([
                'vendor_id' => 'The vendor does not exist.',
            ]);
        }

        if (
            $product->parent_account_id !== $this->parent_account_id
            || $vendor->parent_account_id !== $this->parent_account_id
        ) {
            throw ValidationException::withMessages([
                'parent_account_id' => 'Product master and vendor must belong to the offering parent account.',
            ]);
        }
    }

    private function syncDiscontinuedTimestamp(): void
    {
        if ($this->status === VendorProductOfferingStatus::Discontinued) {
            if ($this->discontinued_at === null) {
                $this->discontinued_at = Carbon::now();
            }

            return;
        }

        $this->discontinued_at = null;
    }
}
