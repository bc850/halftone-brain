<?php

namespace App\Support\Tax;

use App\Enums\TaxCertificateVerificationStatus;
use App\Models\OrganizationCompanyTaxCertificate;
use Illuminate\Support\Carbon;

/**
 * Decides whether an exemption certificate supports exempting a specific sale.
 *
 * Three things must all hold: the certificate is verified, the sale date falls
 * inside its effective window, and it was issued for the jurisdiction being
 * taxed. The exemption category is never part of that decision — a nonprofit,
 * school, or government claim with no verified certificate produces reasons for
 * review, not an exemption.
 *
 * Reads only the values handed to it; performs no queries and writes nothing.
 */
class OrganizationCompanyTaxCertificateApplicability
{
    public const REASON_MISSING_CERTIFICATE = 'missing_certificate';

    public const REASON_PENDING_VERIFICATION = 'certificate_pending_verification';

    public const REASON_REJECTED = 'certificate_rejected';

    public const REASON_REVOKED = 'certificate_revoked';

    public const REASON_EXPIRED = 'certificate_expired';

    public const REASON_NOT_YET_EFFECTIVE = 'certificate_not_yet_effective';

    public const REASON_JURISDICTION_MISMATCH = 'certificate_jurisdiction_mismatch';

    public function evaluate(
        ?OrganizationCompanyTaxCertificate $certificate,
        string $jurisdictionState,
        Carbon|string $asOf,
    ): TaxCertificateApplicability {
        if ($certificate === null) {
            return new TaxCertificateApplicability(false, [self::REASON_MISSING_CERTIFICATE]);
        }

        $date = $asOf instanceof Carbon ? $asOf->copy()->startOfDay() : Carbon::parse($asOf)->startOfDay();
        $reasons = [];

        foreach ($this->verificationReasons($certificate->verification_status) as $reason) {
            $reasons[] = $reason;
        }

        if ($date->lt($certificate->effective_date->copy()->startOfDay())) {
            $reasons[] = self::REASON_NOT_YET_EFFECTIVE;
        }

        $expiration = $certificate->expiration_date;
        if ($expiration !== null && $date->gt($expiration->copy()->endOfDay())) {
            $reasons[] = self::REASON_EXPIRED;
        }

        if (! $this->jurisdictionMatches($certificate->jurisdiction_state, $jurisdictionState)) {
            $reasons[] = self::REASON_JURISDICTION_MISMATCH;
        }

        $reasons = array_values(array_unique($reasons));

        return new TaxCertificateApplicability(
            isApplicable: $reasons === [],
            reasons: $reasons,
            certificateId: $certificate->id,
        );
    }

    /**
     * @return list<string>
     */
    private function verificationReasons(TaxCertificateVerificationStatus $status): array
    {
        return match ($status) {
            TaxCertificateVerificationStatus::Verified => [],
            TaxCertificateVerificationStatus::Pending => [self::REASON_PENDING_VERIFICATION],
            TaxCertificateVerificationStatus::Rejected => [self::REASON_REJECTED],
            TaxCertificateVerificationStatus::Revoked => [self::REASON_REVOKED],
            TaxCertificateVerificationStatus::Expired => [self::REASON_EXPIRED],
        };
    }

    private function jurisdictionMatches(string $certificateState, string $saleState): bool
    {
        return strcasecmp(trim($certificateState), trim($saleState)) === 0;
    }
}
