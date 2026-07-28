<?php

namespace App\Models;

use App\Enums\QuoteLifecycleStatus;
use App\Support\Quotes\ImmutableQuoteRevisionException;
use Database\Factories\QuoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Stable org-scoped quote identity. Financial/customer content lives on QuoteRevision.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property int $deal_id
 * @property int $organization_company_id
 * @property string $quote_number
 * @property QuoteLifecycleStatus $lifecycle_status
 * @property int|null $current_revision_id
 * @property int|null $accepted_revision_id
 * @property int $created_by_membership_id
 * @property int|null $sales_owner_membership_id
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'deal_id',
    'organization_company_id',
    'quote_number',
    'lifecycle_status',
    'current_revision_id',
    'accepted_revision_id',
    'created_by_membership_id',
    'sales_owner_membership_id',
    'lock_version',
])]
class Quote extends Model
{
    /** @use HasFactory<QuoteFactory> */
    use HasFactory;

    public static bool $allowLifecycleMutation = false;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'lifecycle_status' => 'open',
        'lock_version' => 1,
    ];

    protected static function booted(): void
    {
        static::updating(function (Quote $quote): void {
            if (self::$allowLifecycleMutation) {
                return;
            }

            if ($quote->isDirty([
                'lifecycle_status',
                'current_revision_id',
                'accepted_revision_id',
                'lock_version',
                'quote_number',
            ])) {
                throw new ImmutableQuoteRevisionException(
                    'Quote lifecycle fields may only change through quote domain services.'
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lifecycle_status' => QuoteLifecycleStatus::class,
            'lock_version' => 'integer',
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
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * @return BelongsTo<OrganizationCompany, $this>
     */
    public function organizationCompany(): BelongsTo
    {
        return $this->belongsTo(OrganizationCompany::class);
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function createdByMembership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'created_by_membership_id');
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function salesOwnerMembership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'sales_owner_membership_id');
    }

    /**
     * @return BelongsTo<QuoteRevision, $this>
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(QuoteRevision::class, 'current_revision_id');
    }

    /**
     * @return BelongsTo<QuoteRevision, $this>
     */
    public function acceptedRevision(): BelongsTo
    {
        return $this->belongsTo(QuoteRevision::class, 'accepted_revision_id');
    }

    /**
     * @return HasMany<QuoteRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(QuoteRevision::class);
    }

    /**
     * @return HasMany<QuoteStatusEvent, $this>
     */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(QuoteStatusEvent::class);
    }

    /**
     * @return HasMany<QuoteRevisionDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(QuoteRevisionDocument::class);
    }

    /**
     * @return HasMany<QuoteDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(QuoteDelivery::class);
    }

    /**
     * @return HasMany<QuoteCustomerAccessToken, $this>
     */
    public function customerAccessTokens(): HasMany
    {
        return $this->hasMany(QuoteCustomerAccessToken::class);
    }

    /**
     * @return HasMany<QuoteCustomerResponseEvent, $this>
     */
    public function customerResponseEvents(): HasMany
    {
        return $this->hasMany(QuoteCustomerResponseEvent::class);
    }
}
