<?php

namespace App\Models;

use App\Enums\QuoteDeliveryChannel;
use App\Enums\QuoteDeliveryStatus;
use Database\Factories\QuoteDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Delivery-attempt identity for a customer quote document.
 *
 * Status is intentionally excluded from fillable so only future delivery
 * services may forceFill lifecycle transitions.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property int $quote_id
 * @property int $quote_revision_id
 * @property int $quote_revision_document_id
 * @property QuoteDeliveryChannel $channel
 * @property QuoteDeliveryStatus $status
 * @property string $recipient_name_snapshot
 * @property string $recipient_email_snapshot
 * @property array<int, mixed>|null $cc_recipients_snapshot_json
 * @property string|null $provider_key
 * @property string|null $external_message_id
 * @property string $idempotency_key
 * @property int $requested_by_membership_id
 * @property int $requested_by_user_id
 * @property Carbon $requested_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'quote_id',
    'quote_revision_id',
    'quote_revision_document_id',
    'channel',
    'recipient_name_snapshot',
    'recipient_email_snapshot',
    'cc_recipients_snapshot_json',
    'provider_key',
    'external_message_id',
    'idempotency_key',
    'requested_by_membership_id',
    'requested_by_user_id',
    'requested_at',
    'sent_at',
    'failed_at',
    'failure_code',
    'failure_message',
])]
class QuoteDelivery extends Model
{
    /** @use HasFactory<QuoteDeliveryFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'channel' => 'email',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => QuoteDeliveryChannel::class,
            'status' => QuoteDeliveryStatus::class,
            'cc_recipients_snapshot_json' => 'array',
            'requested_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<QuoteRevisionDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(QuoteRevisionDocument::class, 'quote_revision_document_id');
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
     * @return HasMany<QuoteDeliveryEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(QuoteDeliveryEvent::class);
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
