<?php

namespace App\Models;

use App\Enums\PermissionEffect;
use Database\Factories\ParentAccountMembershipPermissionOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $parent_account_membership_id
 * @property int $permission_id
 * @property PermissionEffect $effect
 * @property string $reason
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['parent_account_membership_id', 'permission_id', 'effect', 'reason', 'created_by_user_id'])]
class ParentAccountMembershipPermissionOverride extends Model
{
    /** @use HasFactory<ParentAccountMembershipPermissionOverrideFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effect' => PermissionEffect::class,
        ];
    }

    /**
     * @return BelongsTo<ParentAccountMembership, $this>
     */
    public function parentAccountMembership(): BelongsTo
    {
        return $this->belongsTo(ParentAccountMembership::class);
    }

    /**
     * @return BelongsTo<Permission, $this>
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
