<?php

namespace App\Models;

use App\Enums\QuoteApprovalRequestStatus;
use App\Support\Quotes\QuoteRevisionTaxGuard;
use Database\Factories\QuoteApprovalRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Approval request raised against a quote revision.
 *
 * `pending_guard` mirrors the revision id while pending and is nulled on any
 * resolution, which is what makes the unique index a one-pending-per-revision
 * rule. It is maintained here so callers never have to remember it.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property int $quote_id
 * @property int $quote_revision_id
 * @property int $request_version
 * @property QuoteApprovalRequestStatus $status
 * @property int|null $pending_guard
 * @property array<string, mixed>|null $rule_snapshot_json
 * @property int $requested_by_membership_id
 * @property int $requested_by_user_id
 * @property Carbon $requested_at
 * @property Carbon|null $resolved_at
 * @property string $correlation_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'quote_id',
    'quote_revision_id',
    'request_version',
    'status',
    'rule_snapshot_json',
    'requested_by_membership_id',
    'requested_by_user_id',
    'requested_at',
    'resolved_at',
    'correlation_id',
])]
class QuoteApprovalRequest extends Model
{
    /** @use HasFactory<QuoteApprovalRequestFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'request_version' => 1,
    ];

    protected static function booted(): void
    {
        static::creating(function (QuoteApprovalRequest $request): void {
            QuoteRevisionTaxGuard::assertMayAttachTo($request->quote_revision_id, 'Approval request');
        });

        static::saving(function (QuoteApprovalRequest $request): void {
            $request->pending_guard = $request->status === QuoteApprovalRequestStatus::Pending
                ? $request->quote_revision_id
                : null;
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuoteApprovalRequestStatus::class,
            'request_version' => 'integer',
            'pending_guard' => 'integer',
            'rule_snapshot_json' => 'array',
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
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
     * @return HasMany<QuoteApprovalDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(QuoteApprovalDecision::class);
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function requestedByMembership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'requested_by_membership_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
