<?php

namespace App\Models;

use Database\Factories\AuditEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $parent_account_id
 * @property int|null $organization_id
 * @property int|null $actor_user_id
 * @property string $action
 * @property string $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $before_json
 * @property array<string, mixed>|null $after_json
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $correlation_id
 * @property Carbon|null $created_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'actor_user_id',
    'action',
    'subject_type',
    'subject_id',
    'before_json',
    'after_json',
    'ip',
    'user_agent',
    'correlation_id',
])]
class AuditEvent extends Model
{
    /** @use HasFactory<AuditEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Audit events are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('Audit events are append-only and cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before_json' => 'array',
            'after_json' => 'array',
        ];
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
