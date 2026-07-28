<?php

namespace App\Models;

use App\Enums\QuoteCustomerResponseSource;
use App\Enums\QuoteCustomerResponseType;
use Database\Factories\QuoteCustomerResponseEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only terminal customer response for a quote revision.
 *
 * Exactly one row may exist per revision. Acceptance requires terms acceptance
 * and a non-empty typed name. IP addresses use Laravel's encrypted cast.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property int $quote_id
 * @property int $quote_revision_id
 * @property int $quote_revision_document_id
 * @property int|null $quote_customer_access_token_id
 * @property QuoteCustomerResponseType $response
 * @property QuoteCustomerResponseSource $source
 * @property string $typed_name_snapshot
 * @property string|null $customer_email_snapshot
 * @property bool $terms_accepted
 * @property string $terms_document_checksum
 * @property string|null $rejection_reason
 * @property int|null $employee_membership_id
 * @property int|null $employee_user_id
 * @property string|null $employee_recorded_reason
 * @property string|null $ip_address_encrypted
 * @property string|null $user_agent
 * @property Carbon $occurred_at
 * @property string $correlation_id
 * @property Carbon|null $created_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'quote_id',
    'quote_revision_id',
    'quote_revision_document_id',
    'quote_customer_access_token_id',
    'response',
    'source',
    'typed_name_snapshot',
    'customer_email_snapshot',
    'terms_accepted',
    'terms_document_checksum',
    'rejection_reason',
    'employee_membership_id',
    'employee_user_id',
    'employee_recorded_reason',
    'ip_address_encrypted',
    'user_agent',
    'occurred_at',
    'correlation_id',
])]
class QuoteCustomerResponseEvent extends Model
{
    /** @use HasFactory<QuoteCustomerResponseEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'terms_accepted' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (QuoteCustomerResponseEvent $event): void {
            if ($event->response->requiresTermsAndTypedName()) {
                if (! $event->terms_accepted) {
                    throw new LogicException('Accepted quote responses require terms acceptance.');
                }

                if (trim($event->typed_name_snapshot) === '') {
                    throw new LogicException('Accepted quote responses require a typed name.');
                }
            }

            if ($event->user_agent !== null && strlen($event->user_agent) > 512) {
                throw new LogicException('Quote customer response user_agent may not exceed 512 characters.');
            }
        });

        static::updating(function (): void {
            throw new LogicException('Quote customer response events are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('Quote customer response events are append-only and cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response' => QuoteCustomerResponseType::class,
            'source' => QuoteCustomerResponseSource::class,
            'terms_accepted' => 'boolean',
            'ip_address_encrypted' => 'encrypted',
            'occurred_at' => 'datetime',
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
     * @return BelongsTo<QuoteCustomerAccessToken, $this>
     */
    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(QuoteCustomerAccessToken::class, 'quote_customer_access_token_id');
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function employeeMembership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'employee_membership_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function employeeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_user_id');
    }
}
