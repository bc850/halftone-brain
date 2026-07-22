<?php

namespace App\Models;

use App\Enums\DealStage;
use Carbon\Carbon;
use Database\Factories\DealFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property int $company_id
 * @property int|null $primary_contact_id
 * @property int $owner_id
 * @property DealStage $stage
 * @property int|null $amount_cents
 * @property Carbon|null $expected_close_date
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'organization_id',
    'parent_account_id',
    'company_id',
    'organization_company_id',
    'primary_contact_id',
    'owner_id',
    'stage',
    'amount_cents',
    'expected_close_date',
    'notes',
])]
class Deal extends Model
{
    /** @use HasFactory<DealFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => DealStage::class,
            'amount_cents' => 'integer',
            'expected_close_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function primaryContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'primary_contact_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<Contact, $this>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'deal_contact')->withTimestamps();
    }

    /**
     * @param  Builder<Deal>  $query
     * @return Builder<Deal>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->canSeeEveryone()) {
            return $query;
        }

        return $query->where('owner_id', $user->id);
    }
}
