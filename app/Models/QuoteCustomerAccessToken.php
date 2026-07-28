<?php

namespace App\Models;

use App\Enums\QuoteCustomerAccessTokenPurpose;
use App\Support\Quotes\Security\QuoteCustomerAccessTokenGenerator;
use Database\Factories\QuoteCustomerAccessTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Customer access token bound to an immutable quote document.
 *
 * Only the SHA-256 hex digest is stored. Never persist, log, or audit the raw
 * token value.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property int $quote_id
 * @property int $quote_revision_id
 * @property int $quote_revision_document_id
 * @property string $token_hash
 * @property QuoteCustomerAccessTokenPurpose $purpose
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoke_reason
 * @property int $created_by_membership_id
 * @property int $created_by_user_id
 * @property Carbon|null $last_viewed_at
 * @property int $view_count
 * @property Carbon|null $terminal_response_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'quote_id',
    'quote_revision_id',
    'quote_revision_document_id',
    'token_hash',
    'purpose',
    'expires_at',
    'revoked_at',
    'revoke_reason',
    'created_by_membership_id',
    'created_by_user_id',
    'last_viewed_at',
    'view_count',
    'terminal_response_at',
])]
class QuoteCustomerAccessToken extends Model
{
    /** @use HasFactory<QuoteCustomerAccessTokenFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'purpose' => 'view_and_respond',
        'view_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => QuoteCustomerAccessTokenPurpose::class,
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'terminal_response_at' => 'datetime',
            'view_count' => 'integer',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(?Carbon $at = null): bool
    {
        return (new QuoteCustomerAccessTokenGenerator)->isExpired($this->expires_at, $at);
    }

    public function isUsable(?Carbon $at = null): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired($at);
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
     * @return BelongsTo<Membership, $this>
     */
    public function createdByMembership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'created_by_membership_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
