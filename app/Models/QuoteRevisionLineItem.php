<?php

namespace App\Models;

use App\Enums\QuoteLineDiscountMethod;
use App\Enums\QuoteLineType;
use App\Models\Concerns\GuardsImmutableQuoteRevisionChildren;
use Database\Factories\QuoteRevisionLineItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Quote revision line item. Snapshots are authoritative after save.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property int $quote_id
 * @property int $quote_revision_id
 * @property int $position
 * @property QuoteLineType $line_type
 * @property int|null $product_id
 * @property int|null $organization_product_id
 * @property string|null $sku_snapshot
 * @property string $name_snapshot
 * @property string|null $customer_description_snapshot
 * @property string|null $internal_description_snapshot
 * @property string|null $item_kind_snapshot
 * @property int $quantity_scaled
 * @property string|null $uom_snapshot
 * @property int|null $calculated_unit_price_cents
 * @property int|null $final_unit_price_cents
 * @property int $extended_price_cents
 * @property QuoteLineDiscountMethod $line_discount_method
 * @property int $line_discount_value
 * @property int $line_discount_amount_cents
 * @property int $net_line_total_cents
 * @property bool $is_taxable
 * @property bool $price_override
 * @property string|null $override_reason
 * @property bool $below_minimum
 * @property bool $approval_required
 * @property array<string, mixed>|null $approval_reason_json
 * @property int|null $material_cost_micro_units
 * @property int|null $labor_cost_micro_units
 * @property int|null $overhead_cost_micro_units
 * @property int|null $total_cost_micro_units
 * @property string|null $pricing_method_snapshot
 * @property int|null $markup_basis_points_snapshot
 * @property int|null $margin_basis_points_snapshot
 * @property int|null $pricing_version_snapshot
 * @property int|null $components_version_snapshot
 * @property array<string, mixed>|null $component_cost_breakdown_json
 * @property array<string, mixed>|null $pricing_input_snapshot_json
 * @property array<string, mixed>|null $pricing_result_snapshot_json
 * @property array<string, mixed>|null $configurable_input_snapshot_json
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'quote_id',
    'quote_revision_id',
    'position',
    'line_type',
    'product_id',
    'organization_product_id',
    'sku_snapshot',
    'name_snapshot',
    'customer_description_snapshot',
    'internal_description_snapshot',
    'item_kind_snapshot',
    'quantity_scaled',
    'uom_snapshot',
    'calculated_unit_price_cents',
    'final_unit_price_cents',
    'extended_price_cents',
    'line_discount_method',
    'line_discount_value',
    'line_discount_amount_cents',
    'net_line_total_cents',
    'is_taxable',
    'price_override',
    'override_reason',
    'below_minimum',
    'approval_required',
    'approval_reason_json',
    'material_cost_micro_units',
    'labor_cost_micro_units',
    'overhead_cost_micro_units',
    'total_cost_micro_units',
    'pricing_method_snapshot',
    'markup_basis_points_snapshot',
    'margin_basis_points_snapshot',
    'pricing_version_snapshot',
    'components_version_snapshot',
    'component_cost_breakdown_json',
    'pricing_input_snapshot_json',
    'pricing_result_snapshot_json',
    'configurable_input_snapshot_json',
])]
class QuoteRevisionLineItem extends Model
{
    use GuardsImmutableQuoteRevisionChildren;

    /** @use HasFactory<QuoteRevisionLineItemFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'line_type' => 'custom',
        'quantity_scaled' => 0,
        'extended_price_cents' => 0,
        'line_discount_method' => 'none',
        'line_discount_value' => 0,
        'line_discount_amount_cents' => 0,
        'net_line_total_cents' => 0,
        'is_taxable' => true,
        'price_override' => false,
        'below_minimum' => false,
        'approval_required' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_type' => QuoteLineType::class,
            'line_discount_method' => QuoteLineDiscountMethod::class,
            'quantity_scaled' => 'integer',
            'calculated_unit_price_cents' => 'integer',
            'final_unit_price_cents' => 'integer',
            'extended_price_cents' => 'integer',
            'line_discount_value' => 'integer',
            'line_discount_amount_cents' => 'integer',
            'net_line_total_cents' => 'integer',
            'is_taxable' => 'boolean',
            'price_override' => 'boolean',
            'below_minimum' => 'boolean',
            'approval_required' => 'boolean',
            'approval_reason_json' => 'array',
            'material_cost_micro_units' => 'integer',
            'labor_cost_micro_units' => 'integer',
            'overhead_cost_micro_units' => 'integer',
            'total_cost_micro_units' => 'integer',
            'markup_basis_points_snapshot' => 'integer',
            'margin_basis_points_snapshot' => 'integer',
            'pricing_version_snapshot' => 'integer',
            'components_version_snapshot' => 'integer',
            'component_cost_breakdown_json' => 'array',
            'pricing_input_snapshot_json' => 'array',
            'pricing_result_snapshot_json' => 'array',
            'configurable_input_snapshot_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<QuoteRevision, $this>
     */
    public function quoteRevision(): BelongsTo
    {
        return $this->belongsTo(QuoteRevision::class);
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<OrganizationProduct, $this>
     */
    public function organizationProduct(): BelongsTo
    {
        return $this->belongsTo(OrganizationProduct::class);
    }
}
