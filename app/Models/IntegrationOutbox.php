<?php

namespace App\Models;

use App\Enums\IntegrationOutboxStatus;
use Database\Factories\IntegrationOutboxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Durable post-transaction integration outbox row.
 *
 * Status is intentionally excluded from fillable so only a future dispatcher
 * may forceFill lifecycle transitions. Payloads must stay free of secrets.
 *
 * @property int $id
 * @property int|null $parent_account_id
 * @property int|null $organization_id
 * @property string $aggregate_type
 * @property int $aggregate_id
 * @property string $event_type
 * @property int $schema_version
 * @property array<string, mixed> $payload_json
 * @property string $idempotency_key
 * @property IntegrationOutboxStatus $status
 * @property int $attempt_count
 * @property Carbon $available_at
 * @property Carbon|null $locked_at
 * @property string|null $locked_by_worker
 * @property Carbon|null $dispatched_at
 * @property string|null $last_error_code
 * @property string|null $last_error_message
 * @property string $correlation_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'aggregate_type',
    'aggregate_id',
    'event_type',
    'schema_version',
    'payload_json',
    'idempotency_key',
    'attempt_count',
    'available_at',
    'locked_at',
    'locked_by_worker',
    'dispatched_at',
    'last_error_code',
    'last_error_message',
    'correlation_id',
])]
class IntegrationOutbox extends Model
{
    /** @use HasFactory<IntegrationOutboxFactory> */
    use HasFactory;

    protected $table = 'integration_outbox';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'schema_version' => 1,
        'attempt_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IntegrationOutboxStatus::class,
            'payload_json' => 'array',
            'schema_version' => 'integer',
            'aggregate_id' => 'integer',
            'attempt_count' => 'integer',
            'available_at' => 'datetime',
            'locked_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
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
