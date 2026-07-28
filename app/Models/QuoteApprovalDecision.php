<?php

namespace App\Models;

use App\Enums\QuoteApprovalDecisionType;
use Database\Factories\QuoteApprovalDecisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only record of who approved or rejected an approval request.
 *
 * A rejection must carry a reason. The column is nullable because approvals do
 * not need one, so the requirement is enforced here where it can explain itself.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property int $quote_approval_request_id
 * @property int $quote_id
 * @property int $quote_revision_id
 * @property QuoteApprovalDecisionType $decision
 * @property int $approver_membership_id
 * @property int $approver_user_id
 * @property string|null $reason
 * @property array<string, mixed>|null $metadata_json
 * @property Carbon $decided_at
 * @property Carbon|null $created_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'quote_approval_request_id',
    'quote_id',
    'quote_revision_id',
    'decision',
    'approver_membership_id',
    'approver_user_id',
    'reason',
    'metadata_json',
    'decided_at',
])]
class QuoteApprovalDecision extends Model
{
    /** @use HasFactory<QuoteApprovalDecisionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(function (QuoteApprovalDecision $decision): void {
            if ($decision->decision->requiresReason() && trim((string) $decision->reason) === '') {
                throw new LogicException('A rejected quote approval decision requires a reason.');
            }
        });

        static::updating(function (): void {
            throw new LogicException('Quote approval decisions are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('Quote approval decisions are append-only and cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decision' => QuoteApprovalDecisionType::class,
            'metadata_json' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<QuoteApprovalRequest, $this>
     */
    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(QuoteApprovalRequest::class, 'quote_approval_request_id');
    }

    /**
     * @return BelongsTo<QuoteRevision, $this>
     */
    public function quoteRevision(): BelongsTo
    {
        return $this->belongsTo(QuoteRevision::class);
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function approverMembership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'approver_membership_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
