<?php

namespace App\Models;

use App\Enums\IntegrationOutboxDeliveryStatus;
use Database\Factories\IntegrationOutboxDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-consumer delivery state for an integration outbox event.
 *
 * Lifecycle fields (status, locks, terminal timestamps, errors, provider refs)
 * are intentionally excluded from fillable. Processors must forceFill transitions.
 *
 * @property int $id
 * @property int|null $parent_account_id
 * @property int|null $organization_id
 * @property int $integration_outbox_id
 * @property string $consumer_key
 * @property string $idempotency_key
 * @property IntegrationOutboxDeliveryStatus $status
 * @property int $attempt_count
 * @property Carbon $available_at
 * @property Carbon|null $locked_at
 * @property string|null $locked_by_worker
 * @property Carbon|null $succeeded_at
 * @property Carbon|null $blocked_at
 * @property Carbon|null $abandoned_at
 * @property string|null $last_error_code
 * @property string|null $last_error_message
 * @property array<string, mixed>|null $provider_reference_json
 * @property string $correlation_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'integration_outbox_id',
    'consumer_key',
    'idempotency_key',
    'attempt_count',
    'available_at',
    'correlation_id',
])]
class IntegrationOutboxDelivery extends Model
{
    /** @use HasFactory<IntegrationOutboxDeliveryFactory> */
    use HasFactory;

    protected $table = 'integration_outbox_deliveries';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'attempt_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IntegrationOutboxDeliveryStatus::class,
            'attempt_count' => 'integer',
            'available_at' => 'datetime',
            'locked_at' => 'datetime',
            'succeeded_at' => 'datetime',
            'blocked_at' => 'datetime',
            'abandoned_at' => 'datetime',
            'provider_reference_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<IntegrationOutbox, $this>
     */
    public function outbox(): BelongsTo
    {
        return $this->belongsTo(IntegrationOutbox::class, 'integration_outbox_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<ParentAccount, $this>
     */
    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(ParentAccount::class);
    }
}
