<?php

namespace App\Models;

use App\Enums\QuoteDocumentGenerationStatus;
use App\Enums\QuoteDocumentType;
use Database\Factories\QuoteRevisionDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable generated customer-quote document snapshot for a revision.
 *
 * Each generation attempt is a new versioned row. Private storage paths never
 * hold public URLs. Customer payload snapshots must stay free of internal cost
 * and approval metadata.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property int $quote_id
 * @property int $quote_revision_id
 * @property QuoteDocumentType $document_type
 * @property int $document_version
 * @property QuoteDocumentGenerationStatus $generation_status
 * @property array<string, mixed>|null $customer_payload_snapshot_json
 * @property string|null $private_html_path
 * @property string|null $private_pdf_path
 * @property string|null $mime_type
 * @property int|null $byte_size
 * @property string|null $content_sha256
 * @property int|null $generated_by_membership_id
 * @property int|null $generated_by_user_id
 * @property Carbon|null $generated_at
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property string $correlation_id
 * @property Carbon|null $created_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'quote_id',
    'quote_revision_id',
    'document_type',
    'document_version',
    'generation_status',
    'customer_payload_snapshot_json',
    'private_html_path',
    'private_pdf_path',
    'mime_type',
    'byte_size',
    'content_sha256',
    'generated_by_membership_id',
    'generated_by_user_id',
    'generated_at',
    'failure_code',
    'failure_message',
    'correlation_id',
])]
class QuoteRevisionDocument extends Model
{
    /** @use HasFactory<QuoteRevisionDocumentFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Escape hatch for Pending → Generated/Failed finalization only.
     * Generated documents remain immutable forever.
     */
    public static bool $allowGenerationFinalization = false;

    /**
     * Fields a generation service may write while finalizing a pending row.
     *
     * @var list<string>
     */
    public const GENERATION_FINALIZATION_FIELDS = [
        'generation_status',
        'customer_payload_snapshot_json',
        'private_html_path',
        'private_pdf_path',
        'mime_type',
        'byte_size',
        'content_sha256',
        'generated_by_membership_id',
        'generated_by_user_id',
        'generated_at',
        'failure_code',
        'failure_message',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'document_type' => 'customer_quote',
        'generation_status' => 'pending',
        'document_version' => 1,
    ];

    protected static function booted(): void
    {
        static::updating(function (QuoteRevisionDocument $document): void {
            if (! self::$allowGenerationFinalization) {
                throw new LogicException('Quote revision documents are immutable and cannot be updated.');
            }

            $originalStatus = $document->getRawOriginal('generation_status');
            $from = is_string($originalStatus)
                ? QuoteDocumentGenerationStatus::from($originalStatus)
                : $document->generation_status;

            if ($from !== QuoteDocumentGenerationStatus::Pending) {
                throw new LogicException('Only pending quote revision documents may be finalized.');
            }

            $to = $document->generation_status;
            if (! in_array($to, [
                QuoteDocumentGenerationStatus::Generated,
                QuoteDocumentGenerationStatus::Failed,
            ], true)) {
                throw new LogicException('Pending documents may only finalize to generated or failed.');
            }

            $dirty = array_keys($document->getDirty());
            $forbidden = array_values(array_diff($dirty, self::GENERATION_FINALIZATION_FIELDS));
            if ($forbidden !== []) {
                throw new LogicException(
                    'Document finalization cannot change non-finalization fields: '.implode(', ', $forbidden)
                );
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Quote revision documents are immutable and cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => QuoteDocumentType::class,
            'generation_status' => QuoteDocumentGenerationStatus::class,
            'document_version' => 'integer',
            'customer_payload_snapshot_json' => 'array',
            'byte_size' => 'integer',
            'generated_at' => 'datetime',
        ];
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
     * @return BelongsTo<Membership, $this>
     */
    public function generatedByMembership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'generated_by_membership_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }
}
