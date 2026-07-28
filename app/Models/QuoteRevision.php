<?php

namespace App\Models;

use App\Enums\QuoteRevisionStatus;
use App\Enums\QuoteTaxCalculationStatus;
use App\Support\Quotes\ImmutableQuoteRevisionException;
use Database\Factories\QuoteRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Customer-visible / financial quote version. Immutable after send.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property int $quote_id
 * @property int $revision_number
 * @property int|null $source_revision_id
 * @property QuoteRevisionStatus $status
 * @property int $lock_version
 * @property string $currency_code
 * @property Carbon|null $issue_date
 * @property Carbon|null $expiration_date
 * @property string|null $introduction
 * @property string|null $customer_notes
 * @property string|null $terms_text
 * @property string|null $internal_notes
 * @property int $subtotal_cents
 * @property int $discount_cents
 * @property int $taxable_amount_cents
 * @property int $tax_cents
 * @property int $grand_total_cents
 * @property QuoteTaxCalculationStatus $tax_calculation_status
 * @property array<string, mixed>|null $tax_snapshot_json
 * @property Carbon|null $tax_calculated_at
 * @property int|null $current_tax_calculation_id
 * @property int|null $current_approval_request_id
 * @property int|null $requested_deposit_cents
 * @property bool $approval_required
 * @property array<string, mixed>|null $approval_reason_snapshot
 * @property Carbon|null $pricing_snapshotted_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $viewed_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $rejected_at
 * @property Carbon|null $expired_at
 * @property Carbon|null $superseded_at
 * @property Carbon|null $voided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'quote_id',
    'revision_number',
    'source_revision_id',
    'status',
    'lock_version',
    'currency_code',
    'issue_date',
    'expiration_date',
    'introduction',
    'customer_notes',
    'terms_text',
    'internal_notes',
    'subtotal_cents',
    'discount_cents',
    'taxable_amount_cents',
    'tax_cents',
    'grand_total_cents',
    'tax_calculation_status',
    'tax_snapshot_json',
    'tax_calculated_at',
    'current_tax_calculation_id',
    'current_approval_request_id',
    'requested_deposit_cents',
    'approval_required',
    'approval_reason_snapshot',
    'pricing_snapshotted_at',
    'sent_at',
    'viewed_at',
    'accepted_at',
    'rejected_at',
    'expired_at',
    'superseded_at',
    'voided_at',
])]
class QuoteRevision extends Model
{
    /** @use HasFactory<QuoteRevisionFactory> */
    use HasFactory;

    public static bool $allowLifecycleMutation = false;

    /**
     * Customer-visible / financial snapshot fields — locked after send.
     *
     * The tax and approval pointers appear here and in LIFECYCLE_FIELDS: a status
     * transition may move them, but nothing may repoint them once the revision is
     * customer-visible.
     *
     * @var list<string>
     */
    public const CONTENT_FIELDS = [
        'currency_code',
        'issue_date',
        'expiration_date',
        'introduction',
        'customer_notes',
        'terms_text',
        'internal_notes',
        'subtotal_cents',
        'discount_cents',
        'taxable_amount_cents',
        'tax_cents',
        'grand_total_cents',
        'tax_calculation_status',
        'tax_snapshot_json',
        'tax_calculated_at',
        'current_tax_calculation_id',
        'current_approval_request_id',
        'requested_deposit_cents',
        'approval_required',
        'approval_reason_snapshot',
        'pricing_snapshotted_at',
        'revision_number',
        'source_revision_id',
        'quote_id',
        'organization_id',
        'parent_account_id',
    ];

    /**
     * @var list<string>
     */
    public const LIFECYCLE_FIELDS = [
        'status',
        'lock_version',
        'current_tax_calculation_id',
        'current_approval_request_id',
        'sent_at',
        'viewed_at',
        'accepted_at',
        'rejected_at',
        'expired_at',
        'superseded_at',
        'voided_at',
        'updated_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'lock_version' => 1,
        'currency_code' => 'USD',
        'subtotal_cents' => 0,
        'discount_cents' => 0,
        'taxable_amount_cents' => 0,
        'tax_cents' => 0,
        'grand_total_cents' => 0,
        'tax_calculation_status' => 'pending',
        'approval_required' => false,
    ];

    protected static function booted(): void
    {
        static::updating(function (QuoteRevision $revision): void {
            if (self::$allowLifecycleMutation) {
                $dirty = array_keys($revision->getDirty());
                $forbidden = array_values(array_diff($dirty, self::LIFECYCLE_FIELDS));
                if ($forbidden !== []) {
                    throw new ImmutableQuoteRevisionException(
                        'Lifecycle mutation cannot change quote revision content fields: '.implode(', ', $forbidden)
                    );
                }

                return;
            }

            if ($revision->isDirty('status')) {
                throw new ImmutableQuoteRevisionException(
                    'Quote revision status may only change through QuoteRevisionTransitionService.'
                );
            }

            $originalStatus = $revision->getRawOriginal('status');
            if (! is_string($originalStatus)) {
                return;
            }

            $status = QuoteRevisionStatus::from($originalStatus);
            if (! $status->isCustomerContentImmutable()) {
                return;
            }

            $dirtyContent = array_values(array_intersect(array_keys($revision->getDirty()), self::CONTENT_FIELDS));
            if ($dirtyContent !== []) {
                throw new ImmutableQuoteRevisionException(
                    "Quote revision [{$revision->id}] is {$status->value} and cannot change content fields."
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuoteRevisionStatus::class,
            'lock_version' => 'integer',
            'issue_date' => 'date',
            'expiration_date' => 'date',
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'taxable_amount_cents' => 'integer',
            'tax_cents' => 'integer',
            'grand_total_cents' => 'integer',
            'tax_calculation_status' => QuoteTaxCalculationStatus::class,
            'tax_snapshot_json' => 'array',
            'tax_calculated_at' => 'datetime',
            'current_tax_calculation_id' => 'integer',
            'current_approval_request_id' => 'integer',
            'requested_deposit_cents' => 'integer',
            'approval_required' => 'boolean',
            'approval_reason_snapshot' => 'array',
            'pricing_snapshotted_at' => 'datetime',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'expired_at' => 'datetime',
            'superseded_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return BelongsTo<QuoteRevision, $this>
     */
    public function sourceRevision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_revision_id');
    }

    /**
     * @return HasMany<QuoteStatusEvent, $this>
     */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(QuoteStatusEvent::class);
    }

    /**
     * @return HasMany<QuoteRevisionLineItem, $this>
     */
    public function lineItems(): HasMany
    {
        return $this->hasMany(QuoteRevisionLineItem::class)->orderBy('position');
    }

    /**
     * @return HasMany<QuoteRevisionAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(QuoteRevisionAdjustment::class)->orderBy('position');
    }

    /**
     * @return HasOne<QuoteRevisionPartySnapshot, $this>
     */
    public function partySnapshot(): HasOne
    {
        return $this->hasOne(QuoteRevisionPartySnapshot::class);
    }

    /**
     * @return HasMany<QuoteRevisionTaxCalculation, $this>
     */
    public function taxCalculations(): HasMany
    {
        return $this->hasMany(QuoteRevisionTaxCalculation::class)->orderBy('calculation_version');
    }

    /**
     * @return BelongsTo<QuoteRevisionTaxCalculation, $this>
     */
    public function currentTaxCalculation(): BelongsTo
    {
        return $this->belongsTo(QuoteRevisionTaxCalculation::class, 'current_tax_calculation_id');
    }

    /**
     * @return HasMany<QuoteApprovalRequest, $this>
     */
    public function approvalRequests(): HasMany
    {
        return $this->hasMany(QuoteApprovalRequest::class)->orderBy('request_version');
    }

    /**
     * @return BelongsTo<QuoteApprovalRequest, $this>
     */
    public function currentApprovalRequest(): BelongsTo
    {
        return $this->belongsTo(QuoteApprovalRequest::class, 'current_approval_request_id');
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
}
