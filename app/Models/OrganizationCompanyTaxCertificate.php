<?php

namespace App\Models;

use App\Enums\TaxCertificateVerificationStatus;
use App\Enums\TaxExemptionCategory;
use Database\Factories\OrganizationCompanyTaxCertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Exemption certificate an organization holds for one of its companies.
 *
 * The `exemption_category` is a claim, never a conclusion: a certificate only
 * supports an exempt outcome when it is verified, inside its effective window,
 * and issued for the jurisdiction being taxed. Evaluate that with
 * `OrganizationCompanyTaxCertificateApplicability`, not by reading the category.
 *
 * `certificate_number` and `internal_notes` are sensitive; keep them out of
 * customer-facing payloads and out of calculation snapshots.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property int $organization_company_id
 * @property TaxExemptionCategory $exemption_category
 * @property string $jurisdiction_state
 * @property string $certificate_form_type
 * @property string|null $certificate_number
 * @property string|null $evidence_reference
 * @property Carbon $effective_date
 * @property Carbon|null $expiration_date
 * @property TaxCertificateVerificationStatus $verification_status
 * @property int|null $verified_by_membership_id
 * @property Carbon|null $verified_at
 * @property string|null $rejection_reason
 * @property string|null $internal_notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'organization_company_id',
    'exemption_category',
    'jurisdiction_state',
    'certificate_form_type',
    'certificate_number',
    'evidence_reference',
    'effective_date',
    'expiration_date',
    'verification_status',
    'verified_by_membership_id',
    'verified_at',
    'rejection_reason',
    'internal_notes',
])]
class OrganizationCompanyTaxCertificate extends Model
{
    /** @use HasFactory<OrganizationCompanyTaxCertificateFactory> */
    use HasFactory;

    /**
     * Columns that must never reach a customer-facing payload.
     *
     * @var list<string>
     */
    public const SENSITIVE_FIELDS = [
        'certificate_number',
        'internal_notes',
        'rejection_reason',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'verification_status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exemption_category' => TaxExemptionCategory::class,
            'verification_status' => TaxCertificateVerificationStatus::class,
            'effective_date' => 'date',
            'expiration_date' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OrganizationCompany, $this>
     */
    public function organizationCompany(): BelongsTo
    {
        return $this->belongsTo(OrganizationCompany::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function verifiedByMembership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'verified_by_membership_id');
    }

    /**
     * Evidence fields safe to persist in a tax calculation snapshot.
     *
     * The certificate number is replaced by a redacted reference so an auditor can
     * still tie a calculation back to a row without the number being copied around.
     *
     * @return array{certificate_id: int, exemption_category: string, jurisdiction_state: string, certificate_form_type: string, verification_status: string, effective_date: string, expiration_date: string|null, certificate_reference: string}
     */
    public function toEvidenceSnapshot(): array
    {
        return [
            'certificate_id' => $this->id,
            'exemption_category' => $this->exemption_category->value,
            'jurisdiction_state' => $this->jurisdiction_state,
            'certificate_form_type' => $this->certificate_form_type,
            'verification_status' => $this->verification_status->value,
            'effective_date' => $this->effective_date->toDateString(),
            'expiration_date' => $this->expiration_date?->toDateString(),
            'certificate_reference' => 'certificate:'.$this->id,
        ];
    }
}
