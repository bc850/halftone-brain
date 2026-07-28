<?php

namespace App\Http\Resources;

use App\Enums\TaxCertificateVerificationStatus;
use App\Models\OrganizationCompanyTaxCertificate;

/**
 * Exemption certificate payload for internal screens.
 *
 * The certificate number, the stored evidence reference, the internal notes, and
 * the rejection reason are customer tax documents rather than quote data, so they
 * are omitted entirely — not nulled — for anyone without certificate view
 * authority. A missing key cannot be mistaken for "there is no certificate
 * number"; a null one can.
 */
final class TaxCertificateResource
{
    /**
     * @param  iterable<int, OrganizationCompanyTaxCertificate>  $certificates
     * @return list<array<string, mixed>>
     */
    public static function collection(iterable $certificates, bool $canViewEvidence): array
    {
        $payload = [];

        foreach ($certificates as $certificate) {
            $payload[] = self::make($certificate, $canViewEvidence);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function make(
        OrganizationCompanyTaxCertificate $certificate,
        bool $canViewEvidence,
    ): array {
        $payload = [
            'id' => $certificate->id,
            'organization_company_id' => $certificate->organization_company_id,
            'exemption_category' => $certificate->exemption_category->value,
            'exemption_category_label' => $certificate->exemption_category->label(),
            'jurisdiction_state' => $certificate->jurisdiction_state,
            'certificate_form_type' => $certificate->certificate_form_type,
            'verification_status' => $certificate->verification_status->value,
            'verification_status_label' => $certificate->verification_status->label(),
            'effective_date' => $certificate->effective_date->toDateString(),
            'expiration_date' => $certificate->expiration_date?->toDateString(),
            'verified_at' => $certificate->verified_at?->toIso8601String(),
            'certificate_reference' => 'certificate:'.$certificate->id,
            // Whether evidence was stored is not itself sensitive, and it explains
            // why a pending certificate cannot be verified yet.
            'has_evidence' => trim((string) $certificate->evidence_reference) !== '',
            'has_rejection_reason' => trim((string) $certificate->rejection_reason) !== '',
            'is_editable' => $certificate->verification_status === TaxCertificateVerificationStatus::Pending,
            'can_support_exemption' => $certificate->verification_status->canSupportExemption(),
        ];

        if (! $canViewEvidence) {
            return $payload;
        }

        return [
            ...$payload,
            'certificate_number' => $certificate->certificate_number,
            'evidence_reference' => $certificate->evidence_reference,
            'internal_notes' => $certificate->internal_notes,
            'rejection_reason' => $certificate->rejection_reason,
        ];
    }
}
