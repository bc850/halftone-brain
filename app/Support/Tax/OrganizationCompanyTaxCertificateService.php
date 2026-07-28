<?php

namespace App\Support\Tax;

use App\Enums\TaxCertificateVerificationStatus;
use App\Enums\TaxExemptionCategory;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationCompany;
use App\Models\OrganizationCompanyTaxCertificate;
use App\Models\ParentAccount;
use App\Models\User;
use App\Support\Audit\Auditor;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records and verifies the exemption evidence an organization holds for a customer.
 *
 * The exemption category is a claim, never a conclusion: a school, nonprofit, or
 * government category on its own never exempts a sale. Only a certificate that is
 * verified, inside its effective window, and issued for the jurisdiction being taxed
 * can do that, which {@see OrganizationCompanyTaxCertificateApplicability} decides at
 * calculation time.
 *
 * Nothing is ever hard deleted. A certificate that turns out to be wrong is rejected,
 * revoked, or expired, so the record of what was relied on and when survives.
 *
 * Audit payloads use the redacted evidence snapshot; the certificate number and
 * internal notes never reach an audit row.
 *
 * Permission checks belong to the caller: `crm.tax_certificate.manage` for every
 * method here. Nothing in this class reads TenantContext.
 */
final class OrganizationCompanyTaxCertificateService
{
    /**
     * @var list<string>
     */
    private const EDITABLE_FIELDS = [
        'exemption_category',
        'jurisdiction_state',
        'certificate_form_type',
        'certificate_number',
        'evidence_reference',
        'effective_date',
        'expiration_date',
        'internal_notes',
    ];

    public function __construct(private Auditor $auditor) {}

    public function create(
        OrganizationCompany $organizationCompany,
        TaxExemptionCategory $exemptionCategory,
        string $jurisdictionState,
        string $certificateFormType,
        CarbonInterface|string $effectiveDate,
        ?string $certificateNumber = null,
        ?string $evidenceReference = null,
        CarbonInterface|string|null $expirationDate = null,
        ?string $internalNotes = null,
        ?User $actor = null,
    ): OrganizationCompanyTaxCertificate {
        $state = $this->requireState($jurisdictionState);
        $formType = $this->requireText($certificateFormType, 'Certificate form type');
        $effective = $this->toDateString($effectiveDate);
        $expiration = $expirationDate === null ? null : $this->toDateString($expirationDate);

        if ($expiration !== null && $expiration < $effective) {
            throw new InvalidTaxConfigurationException(
                'Certificate expiration date cannot precede its effective date.'
            );
        }

        return DB::transaction(function () use (
            $organizationCompany,
            $exemptionCategory,
            $state,
            $formType,
            $effective,
            $certificateNumber,
            $evidenceReference,
            $expiration,
            $internalNotes,
            $actor,
        ): OrganizationCompanyTaxCertificate {
            $certificate = OrganizationCompanyTaxCertificate::query()->create([
                'parent_account_id' => $organizationCompany->parent_account_id,
                'organization_id' => $organizationCompany->organization_id,
                'organization_company_id' => $organizationCompany->id,
                'exemption_category' => $exemptionCategory,
                'jurisdiction_state' => $state,
                'certificate_form_type' => $formType,
                'certificate_number' => $certificateNumber === null ? null : trim($certificateNumber),
                'evidence_reference' => $evidenceReference === null ? null : trim($evidenceReference),
                'effective_date' => $effective,
                'expiration_date' => $expiration,
                'verification_status' => TaxCertificateVerificationStatus::Pending,
            ]);

            if ($internalNotes !== null) {
                $certificate->internal_notes = $internalNotes;
                $certificate->save();
            }

            $this->audit($certificate, 'crm.tax_certificate.created', null, $actor);

            return $certificate;
        });
    }

    /**
     * Editable only while pending. Once verified, the row describes what someone
     * checked, so a correction means a new certificate rather than a quiet edit.
     *
     * @param  array{
     *     exemption_category?: TaxExemptionCategory|string,
     *     jurisdiction_state?: string,
     *     certificate_form_type?: string,
     *     certificate_number?: string|null,
     *     evidence_reference?: string|null,
     *     effective_date?: CarbonInterface|string,
     *     expiration_date?: CarbonInterface|string|null,
     *     internal_notes?: string|null
     * }  $data
     */
    public function update(
        OrganizationCompanyTaxCertificate $certificate,
        array $data,
        ?User $actor = null,
    ): OrganizationCompanyTaxCertificate {
        $unknown = array_diff(array_keys($data), self::EDITABLE_FIELDS);

        if ($unknown !== []) {
            throw new InvalidTaxConfigurationException(
                'Unsupported certificate fields: '.implode(', ', $unknown).'.'
            );
        }

        return DB::transaction(function () use ($certificate, $data, $actor): OrganizationCompanyTaxCertificate {
            $locked = $this->lock($certificate);

            if ($locked->verification_status !== TaxCertificateVerificationStatus::Pending) {
                throw new InvalidTaxConfigurationException(sprintf(
                    'Certificate [%d] is %s and can no longer be edited; record a new certificate instead.',
                    $locked->id,
                    $locked->verification_status->value,
                ));
            }

            $before = $this->payload($locked);

            if (array_key_exists('exemption_category', $data)) {
                $category = $data['exemption_category'];
                $locked->exemption_category = $category instanceof TaxExemptionCategory
                    ? $category
                    : TaxExemptionCategory::from((string) $category);
            }

            if (array_key_exists('jurisdiction_state', $data)) {
                $locked->jurisdiction_state = $this->requireState((string) $data['jurisdiction_state']);
            }

            if (array_key_exists('certificate_form_type', $data)) {
                $locked->certificate_form_type = $this->requireText(
                    (string) $data['certificate_form_type'],
                    'Certificate form type',
                );
            }

            if (array_key_exists('certificate_number', $data)) {
                $number = $data['certificate_number'];
                $locked->certificate_number = $number === null ? null : trim((string) $number);
            }

            if (array_key_exists('evidence_reference', $data)) {
                $reference = $data['evidence_reference'];
                $locked->evidence_reference = $reference === null ? null : trim((string) $reference);
            }

            if (array_key_exists('effective_date', $data)) {
                $locked->effective_date = $this->toDate($data['effective_date']);
            }

            if (array_key_exists('expiration_date', $data)) {
                $expiration = $data['expiration_date'];
                $locked->expiration_date = $expiration === null ? null : $this->toDate($expiration);
            }

            if (array_key_exists('internal_notes', $data)) {
                $notes = $data['internal_notes'];
                $locked->internal_notes = $notes === null ? null : (string) $notes;
            }

            if ($locked->expiration_date !== null
                && $locked->expiration_date->toDateString() < $locked->effective_date->toDateString()) {
                throw new InvalidTaxConfigurationException(
                    'Certificate expiration date cannot precede its effective date.'
                );
            }

            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->save();

            $this->audit($locked, 'crm.tax_certificate.updated', $before, $actor);

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Mark the certificate as checked against real evidence.
     *
     * Verification is what turns a claim into something a calculation may rely on, so
     * it demands a stored reference to the evidence that was reviewed and the date the
     * exemption starts, plus the membership that takes responsibility for the check.
     */
    public function verify(
        OrganizationCompanyTaxCertificate $certificate,
        Membership $verifiedBy,
        ?User $actor = null,
    ): OrganizationCompanyTaxCertificate {
        return DB::transaction(function () use ($certificate, $verifiedBy, $actor): OrganizationCompanyTaxCertificate {
            $locked = $this->lock($certificate);

            if ($locked->verification_status === TaxCertificateVerificationStatus::Verified) {
                return $locked;
            }

            $this->assertStatus($locked, [TaxCertificateVerificationStatus::Pending], 'verified');
            $this->assertMembershipInOrganization($locked, $verifiedBy);

            if (trim((string) $locked->evidence_reference) === '') {
                throw new InvalidTaxConfigurationException(
                    'A certificate cannot be verified without a stored evidence reference.'
                );
            }

            $before = $this->payload($locked);

            $locked->fill([
                'verification_status' => TaxCertificateVerificationStatus::Verified,
                'verified_by_membership_id' => $verifiedBy->id,
                'verified_at' => now(),
                'rejection_reason' => null,
            ]);
            $locked->save();

            $this->audit($locked, 'crm.tax_certificate.verified', $before, $actor);

            return $locked->fresh() ?? $locked;
        });
    }

    public function reject(
        OrganizationCompanyTaxCertificate $certificate,
        Membership $rejectedBy,
        string $reason,
        ?User $actor = null,
    ): OrganizationCompanyTaxCertificate {
        $reason = $this->requireText($reason, 'Rejection reason');

        return DB::transaction(function () use ($certificate, $rejectedBy, $reason, $actor): OrganizationCompanyTaxCertificate {
            $locked = $this->lock($certificate);

            $this->assertStatus($locked, [TaxCertificateVerificationStatus::Pending], 'rejected');
            $this->assertMembershipInOrganization($locked, $rejectedBy);

            $before = $this->payload($locked);

            $locked->fill([
                'verification_status' => TaxCertificateVerificationStatus::Rejected,
                'verified_by_membership_id' => $rejectedBy->id,
                'verified_at' => now(),
                'rejection_reason' => $reason,
            ]);
            $locked->save();

            $this->audit($locked, 'crm.tax_certificate.rejected', $before, $actor);

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Withdraw a previously verified certificate. Calculations already made keep their
     * evidence snapshot; only future calculations lose the exemption.
     */
    public function revoke(
        OrganizationCompanyTaxCertificate $certificate,
        Membership $revokedBy,
        string $reason,
        ?User $actor = null,
    ): OrganizationCompanyTaxCertificate {
        $reason = $this->requireText($reason, 'Revocation reason');

        return DB::transaction(function () use ($certificate, $revokedBy, $reason, $actor): OrganizationCompanyTaxCertificate {
            $locked = $this->lock($certificate);

            $this->assertStatus(
                $locked,
                [TaxCertificateVerificationStatus::Verified, TaxCertificateVerificationStatus::Expired],
                'revoked',
            );
            $this->assertMembershipInOrganization($locked, $revokedBy);

            $before = $this->payload($locked);

            $locked->fill([
                'verification_status' => TaxCertificateVerificationStatus::Revoked,
                'rejection_reason' => $reason,
            ]);
            $locked->save();

            $this->audit($locked, 'crm.tax_certificate.revoked', $before, $actor);

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Record that a certificate has passed its expiration date. Applicability already
     * treats a lapsed window as unusable; this makes the state visible in listings.
     */
    public function markExpired(
        OrganizationCompanyTaxCertificate $certificate,
        ?User $actor = null,
    ): OrganizationCompanyTaxCertificate {
        return DB::transaction(function () use ($certificate, $actor): OrganizationCompanyTaxCertificate {
            $locked = $this->lock($certificate);

            if ($locked->verification_status === TaxCertificateVerificationStatus::Expired) {
                return $locked;
            }

            $this->assertStatus(
                $locked,
                [TaxCertificateVerificationStatus::Pending, TaxCertificateVerificationStatus::Verified],
                'expired',
            );

            if ($locked->expiration_date === null) {
                throw new InvalidTaxConfigurationException(
                    'A certificate without an expiration date cannot expire.'
                );
            }

            if ($locked->expiration_date->copy()->endOfDay()->isFuture()) {
                throw new InvalidTaxConfigurationException(
                    'A certificate cannot be expired before its expiration date.'
                );
            }

            $before = $this->payload($locked);

            $locked->verification_status = TaxCertificateVerificationStatus::Expired;
            $locked->save();

            $this->audit($locked, 'crm.tax_certificate.expired', $before, $actor);

            return $locked->fresh() ?? $locked;
        });
    }

    private function lock(OrganizationCompanyTaxCertificate $certificate): OrganizationCompanyTaxCertificate
    {
        /** @var OrganizationCompanyTaxCertificate $locked */
        $locked = OrganizationCompanyTaxCertificate::query()
            ->whereKey($certificate->id)
            ->lockForUpdate()
            ->firstOrFail();

        return $locked;
    }

    /**
     * @param  list<TaxCertificateVerificationStatus>  $allowed
     */
    private function assertStatus(
        OrganizationCompanyTaxCertificate $certificate,
        array $allowed,
        string $target,
    ): void {
        if (in_array($certificate->verification_status, $allowed, true)) {
            return;
        }

        throw new InvalidTaxConfigurationException(sprintf(
            'Certificate [%d] is %s and cannot be %s.',
            $certificate->id,
            $certificate->verification_status->value,
            $target,
        ));
    }

    private function assertMembershipInOrganization(
        OrganizationCompanyTaxCertificate $certificate,
        Membership $membership,
    ): void {
        if ($membership->organization_id !== $certificate->organization_id) {
            throw new InvalidTaxConfigurationException(
                'Certificate decisions must be recorded by a membership in the same organization.'
            );
        }
    }

    private function toDate(CarbonInterface|string $value): Carbon
    {
        return Carbon::parse($this->toDateString($value))->startOfDay();
    }

    private function toDateString(CarbonInterface|string $value): string
    {
        return $value instanceof CarbonInterface
            ? $value->toDateString()
            : Carbon::parse($value)->toDateString();
    }

    private function requireText(string $value, string $label): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidTaxConfigurationException("{$label} is required.");
        }

        return $trimmed;
    }

    private function requireState(string $state): string
    {
        return strtoupper($this->requireText($state, 'Jurisdiction state'));
    }

    /**
     * Redacted snapshot: the evidence fields plus the verification trail, never the
     * certificate number or internal notes.
     *
     * @return array<string, mixed>
     */
    private function payload(OrganizationCompanyTaxCertificate $certificate): array
    {
        return [
            ...$certificate->toEvidenceSnapshot(),
            'organization_company_id' => $certificate->organization_company_id,
            'evidence_reference_present' => trim((string) $certificate->evidence_reference) !== '',
            'verified_by_membership_id' => $certificate->verified_by_membership_id,
            'verified_at' => $certificate->verified_at?->toIso8601String(),
            'rejection_reason_present' => trim((string) $certificate->rejection_reason) !== '',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    private function audit(
        OrganizationCompanyTaxCertificate $certificate,
        string $action,
        ?array $before,
        ?User $actor,
    ): void {
        $this->auditor->append(
            parentAccount: ParentAccount::query()->findOrFail($certificate->parent_account_id),
            action: $action,
            subjectType: OrganizationCompanyTaxCertificate::class,
            subjectId: $certificate->id,
            organization: Organization::query()->findOrFail($certificate->organization_id),
            actor: $actor,
            before: $before,
            after: $this->payload($certificate),
            correlationId: (string) Str::uuid(),
        );
    }
}
