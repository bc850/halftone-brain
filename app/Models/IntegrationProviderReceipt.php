<?php

namespace App\Models;

use App\Enums\IntegrationProvider;
use App\Enums\IntegrationProviderReceiptDiscoveryMethod;
use App\Enums\IntegrationRemoteResourceType;
use Database\Factories\IntegrationProviderReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only sanitized linkage between a delivery and a remote provider resource.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property int $integration_outbox_delivery_id
 * @property IntegrationProvider $provider
 * @property IntegrationRemoteResourceType $remote_resource_type
 * @property string $remote_id
 * @property string|null $remote_board_id
 * @property string|null $remote_url
 * @property string|null $provider_request_id
 * @property bool $idempotency_replayed
 * @property string $api_version
 * @property Carbon $linked_at
 * @property IntegrationProviderReceiptDiscoveryMethod $discovery_method
 * @property Carbon|null $created_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'integration_outbox_delivery_id',
    'provider',
    'remote_resource_type',
    'remote_id',
    'remote_board_id',
    'remote_url',
    'provider_request_id',
    'idempotency_replayed',
    'api_version',
    'linked_at',
    'discovery_method',
])]
class IntegrationProviderReceipt extends Model
{
    /** @use HasFactory<IntegrationProviderReceiptFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'idempotency_replayed' => false,
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Integration provider receipts are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('Integration provider receipts are append-only and cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'remote_resource_type' => IntegrationRemoteResourceType::class,
            'idempotency_replayed' => 'boolean',
            'linked_at' => 'datetime',
            'discovery_method' => IntegrationProviderReceiptDiscoveryMethod::class,
        ];
    }

    /**
     * @return BelongsTo<IntegrationOutboxDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(IntegrationOutboxDelivery::class, 'integration_outbox_delivery_id');
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
