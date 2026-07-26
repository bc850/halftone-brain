<?php

namespace App\Models;

use App\Enums\InventoryTrackingMode;
use App\Enums\OverheadMode;
use App\Enums\PricingMethod;
use App\Enums\UnitOfMeasure;
use App\Support\Pricing\OrganizationProductPricingMapper;
use App\Support\Pricing\PricingCalculator;
use App\Support\Pricing\PricingInput;
use Database\Factories\OrganizationProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Organization-specific availability and pricing inputs for a shared Product Master.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property int $product_id
 * @property string|null $display_name
 * @property bool $is_available
 * @property bool $is_sellable
 * @property bool $is_purchasable
 * @property InventoryTrackingMode $inventory_tracking_mode
 * @property UnitOfMeasure|null $purchase_unit_of_measure
 * @property UnitOfMeasure|null $stock_unit_of_measure
 * @property UnitOfMeasure|null $usage_unit_of_measure
 * @property int|null $lead_time_days
 * @property string|null $notes
 * @property int $material_cost_micro_units
 * @property int|null $purchase_cost_micro_units
 * @property int $labor_cost_micro_units
 * @property OverheadMode $overhead_mode
 * @property int $overhead_amount_micro_units
 * @property int $overhead_rate_basis_points
 * @property PricingMethod $pricing_method
 * @property int $markup_basis_points
 * @property int $target_margin_basis_points
 * @property int|null $fixed_price_cents
 * @property int|null $minimum_price_cents
 * @property bool $allow_price_override
 * @property string $currency_code
 * @property int $pricing_version
 * @property int $components_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'product_id',
    'display_name',
    'is_available',
    'is_sellable',
    'is_purchasable',
    'inventory_tracking_mode',
    'purchase_unit_of_measure',
    'stock_unit_of_measure',
    'usage_unit_of_measure',
    'lead_time_days',
    'notes',
    'material_cost_micro_units',
    'purchase_cost_micro_units',
    'labor_cost_micro_units',
    'overhead_mode',
    'overhead_amount_micro_units',
    'overhead_rate_basis_points',
    'pricing_method',
    'markup_basis_points',
    'target_margin_basis_points',
    'fixed_price_cents',
    'minimum_price_cents',
    'allow_price_override',
    'currency_code',
    'pricing_version',
    'components_version',
])]
class OrganizationProduct extends Model
{
    /** @use HasFactory<OrganizationProductFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'components_version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'is_sellable' => 'boolean',
            'is_purchasable' => 'boolean',
            'inventory_tracking_mode' => InventoryTrackingMode::class,
            'purchase_unit_of_measure' => UnitOfMeasure::class,
            'stock_unit_of_measure' => UnitOfMeasure::class,
            'usage_unit_of_measure' => UnitOfMeasure::class,
            'lead_time_days' => 'integer',
            'material_cost_micro_units' => 'integer',
            'purchase_cost_micro_units' => 'integer',
            'labor_cost_micro_units' => 'integer',
            'overhead_mode' => OverheadMode::class,
            'overhead_amount_micro_units' => 'integer',
            'overhead_rate_basis_points' => 'integer',
            'pricing_method' => PricingMethod::class,
            'markup_basis_points' => 'integer',
            'target_margin_basis_points' => 'integer',
            'fixed_price_cents' => 'integer',
            'minimum_price_cents' => 'integer',
            'allow_price_override' => 'boolean',
            'pricing_version' => 'integer',
            'components_version' => 'integer',
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
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<OrganizationProductUnitConversion, $this>
     */
    public function unitConversions(): HasMany
    {
        return $this->hasMany(OrganizationProductUnitConversion::class);
    }

    /**
     * Estimated material components for this finished organization product.
     *
     * @return HasMany<OrganizationProductComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(OrganizationProductComponent::class);
    }

    /**
     * Finished items that list this organization product as a material component.
     *
     * @return HasMany<OrganizationProductComponent, $this>
     */
    public function componentUsages(): HasMany
    {
        return $this->hasMany(OrganizationProductComponent::class, 'component_organization_product_id');
    }

    /**
     * Map this record into immutable pricing facts for {@see PricingCalculator}.
     *
     * Does not consult Product Master legacy cost/price columns and performs no writes.
     */
    public function toPricingInput(
        string $quantity = '1',
        ?int $requestedOverridePriceCents = null,
    ): PricingInput {
        return (new OrganizationProductPricingMapper)->toPricingInput(
            $this,
            $quantity,
            $requestedOverridePriceCents,
        );
    }
}
