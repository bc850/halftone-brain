<?php

namespace App\Models;

use App\Enums\QuoteRevisionStatus;
use App\Enums\QuoteStatusTransitionSource;
use Database\Factories\QuoteStatusEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only quote revision status transition log.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property int $quote_id
 * @property int $quote_revision_id
 * @property string|null $from_status
 * @property string $to_status
 * @property int|null $actor_user_id
 * @property int|null $actor_membership_id
 * @property QuoteStatusTransitionSource $transition_source
 * @property array<string, mixed>|null $metadata_json
 * @property Carbon $occurred_at
 * @property string $correlation_id
 * @property Carbon|null $created_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'quote_id',
    'quote_revision_id',
    'from_status',
    'to_status',
    'actor_user_id',
    'actor_membership_id',
    'transition_source',
    'metadata_json',
    'occurred_at',
    'correlation_id',
])]
class QuoteStatusEvent extends Model
{
    /** @use HasFactory<QuoteStatusEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Quote status events are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('Quote status events are append-only and cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transition_source' => QuoteStatusTransitionSource::class,
            'metadata_json' => 'array',
            'occurred_at' => 'datetime',
            'from_status' => QuoteRevisionStatus::class,
            'to_status' => QuoteRevisionStatus::class,
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
    public function quoteRevision(): BelongsTo
    {
        return $this->belongsTo(QuoteRevision::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function actorMembership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'actor_membership_id');
    }
}
