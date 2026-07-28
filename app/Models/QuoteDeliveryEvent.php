<?php

namespace App\Models;

use App\Enums\QuoteDeliveryStatus;
use Database\Factories\QuoteDeliveryEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only delivery status transition log.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property int $quote_id
 * @property int $quote_revision_id
 * @property int $quote_delivery_id
 * @property QuoteDeliveryStatus|null $from_status
 * @property QuoteDeliveryStatus $to_status
 * @property array<string, mixed>|null $metadata_json
 * @property int|null $actor_membership_id
 * @property int|null $actor_user_id
 * @property Carbon $occurred_at
 * @property string $correlation_id
 * @property Carbon|null $created_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'quote_id',
    'quote_revision_id',
    'quote_delivery_id',
    'from_status',
    'to_status',
    'metadata_json',
    'actor_membership_id',
    'actor_user_id',
    'occurred_at',
    'correlation_id',
])]
class QuoteDeliveryEvent extends Model
{
    /** @use HasFactory<QuoteDeliveryEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Quote delivery events are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('Quote delivery events are append-only and cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => QuoteDeliveryStatus::class,
            'to_status' => QuoteDeliveryStatus::class,
            'metadata_json' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<QuoteDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(QuoteDelivery::class, 'quote_delivery_id');
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
    public function actorMembership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'actor_membership_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
