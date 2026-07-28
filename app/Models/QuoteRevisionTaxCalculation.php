<?php

namespace App\Models;

use App\Enums\QuoteTaxCalculationOutcome;
use App\Enums\QuoteTaxCalculationSource;
use App\Support\Quotes\QuoteRevisionTaxGuard;
use Database\Factories\QuoteRevisionTaxCalculationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only tax calculation history for a quote revision.
 *
 * Recalculating writes a new version rather than editing the previous one, so an
 * auditor can always see the rate, jurisdiction, and evidence used at the time.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property int $quote_id
 * @property int $quote_revision_id
 * @property int $calculation_version
 * @property QuoteTaxCalculationOutcome $outcome
 * @property int $taxable_basis_cents
 * @property int|null $rate_ppm
 * @property int $tax_cents
 * @property array<string, mixed>|null $jurisdiction_snapshot_json
 * @property int|null $organization_company_tax_certificate_id
 * @property array<string, mixed>|null $certificate_evidence_snapshot_json
 * @property QuoteTaxCalculationSource $source
 * @property bool $is_override
 * @property string|null $override_reason
 * @property int|null $actor_membership_id
 * @property int|null $actor_user_id
 * @property string $calculator_version
 * @property Carbon $calculated_at
 * @property string $correlation_id
 * @property Carbon|null $created_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'quote_id',
    'quote_revision_id',
    'calculation_version',
    'outcome',
    'taxable_basis_cents',
    'rate_ppm',
    'tax_cents',
    'jurisdiction_snapshot_json',
    'organization_company_tax_certificate_id',
    'certificate_evidence_snapshot_json',
    'source',
    'is_override',
    'override_reason',
    'actor_membership_id',
    'actor_user_id',
    'calculator_version',
    'calculated_at',
    'correlation_id',
])]
class QuoteRevisionTaxCalculation extends Model
{
    /** @use HasFactory<QuoteRevisionTaxCalculationFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'taxable_basis_cents' => 0,
        'tax_cents' => 0,
        'is_override' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (QuoteRevisionTaxCalculation $calculation): void {
            QuoteRevisionTaxGuard::assertMayAttachTo($calculation->quote_revision_id, 'Tax calculation');
        });

        static::updating(function (): void {
            throw new LogicException('Quote revision tax calculations are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('Quote revision tax calculations are append-only and cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => QuoteTaxCalculationOutcome::class,
            'source' => QuoteTaxCalculationSource::class,
            'calculation_version' => 'integer',
            'taxable_basis_cents' => 'integer',
            'rate_ppm' => 'integer',
            'tax_cents' => 'integer',
            'jurisdiction_snapshot_json' => 'array',
            'certificate_evidence_snapshot_json' => 'array',
            'is_override' => 'boolean',
            'calculated_at' => 'datetime',
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
     * @return BelongsTo<OrganizationCompanyTaxCertificate, $this>
     */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(
            OrganizationCompanyTaxCertificate::class,
            'organization_company_tax_certificate_id',
        );
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function actorMembership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'actor_membership_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
