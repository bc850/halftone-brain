<?php

namespace App\Models;

use App\Enums\UnitOfMeasure;
use Carbon\Carbon;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string $sku
 * @property string|null $vendor_sku
 * @property int|null $vendor_id
 * @property int|null $product_category_id
 * @property UnitOfMeasure $unit_of_measure
 * @property int $true_cost_micro_units
 * @property int $markup_basis_points
 * @property int|null $list_price_cents
 * @property string|null $description
 * @property string|null $notes
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parent_account_id',
    'name',
    'sku',
    'vendor_sku',
    'vendor_id',
    'product_category_id',
    'unit_of_measure',
    'true_cost_micro_units',
    'markup_basis_points',
    'list_price_cents',
    'description',
    'notes',
    'is_active',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_of_measure' => UnitOfMeasure::class,
            'true_cost_micro_units' => 'integer',
            'markup_basis_points' => 'integer',
            'list_price_cents' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function relatedProducts(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'product_related',
            'product_id',
            'related_product_id',
        )->withTimestamps();
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
