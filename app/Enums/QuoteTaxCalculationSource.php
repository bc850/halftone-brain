<?php

namespace App\Enums;

enum QuoteTaxCalculationSource: string
{
    case ConfiguredRate = 'configured_rate';
    case VerifiedExemption = 'verified_exemption';
    case ManualOverride = 'manual_override';

    public function requiresReason(): bool
    {
        return $this === self::ManualOverride;
    }
}
