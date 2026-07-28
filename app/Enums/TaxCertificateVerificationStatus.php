<?php

namespace App\Enums;

enum TaxCertificateVerificationStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Revoked = 'revoked';

    /**
     * Only a verified certificate can ever support an exempt outcome, and even
     * then the effective window and jurisdiction still have to match.
     */
    public function canSupportExemption(): bool
    {
        return $this === self::Verified;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending verification',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
            self::Revoked => 'Revoked',
        };
    }
}
