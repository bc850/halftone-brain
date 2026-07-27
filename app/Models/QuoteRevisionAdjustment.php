<?php

namespace App\Models;

use App\Enums\QuoteAdjustmentMethod;
use App\Enums\QuoteAdjustmentType;
use App\Models\Concerns\GuardsImmutableQuoteRevisionChildren;
use Database\Factories\QuoteRevisionAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Quote-level adjustment (discount or positive charge) on a revision.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property int $quote_id
 * @property int $quote_revision_id
 * @property int $position
 * @property QuoteAdjustmentType $adjustment_type
 * @property string $description_snapshot
 * @property QuoteAdjustmentMethod $method
 * @property int $input_value
 * @property int $amount_cents
 * @property bool $is_taxable
 * @property bool $approval_required
 * @property array<string, mixed>|null $approval_reason_json
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'quote_id',
    'quote_revision_id',
    'position',
    'adjustment_type',
    'description_snapshot',
    'method',
    'input_value',
    'amount_cents',
    'is_taxable',
    'approval_required',
    'approval_reason_json',
])]
class QuoteRevisionAdjustment extends Model
{
    use GuardsImmutableQuoteRevisionChildren;

    /** @use HasFactory<QuoteRevisionAdjustmentFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'adjustment_type' => 'other',
        'method' => 'fixed',
        'input_value' => 0,
        'amount_cents' => 0,
        'is_taxable' => false,
        'approval_required' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'adjustment_type' => QuoteAdjustmentType::class,
            'method' => QuoteAdjustmentMethod::class,
            'input_value' => 'integer',
            'amount_cents' => 'integer',
            'is_taxable' => 'boolean',
            'approval_required' => 'boolean',
            'approval_reason_json' => 'array',
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
}
