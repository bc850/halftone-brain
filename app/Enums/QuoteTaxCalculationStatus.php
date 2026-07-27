<?php

namespace App\Enums;

enum QuoteTaxCalculationStatus: string
{
    case Pending = 'pending';
    case Calculated = 'calculated';
    case Exempt = 'exempt';
    case ReviewRequired = 'review_required';

    public function isResolved(): bool
    {
        return match ($this) {
            self::Calculated, self::Exempt => true,
            self::Pending, self::ReviewRequired => false,
        };
    }

    public function blocksCustomerFinalization(): bool
    {
        return match ($this) {
            self::Pending, self::ReviewRequired => true,
            self::Calculated, self::Exempt => false,
        };
    }
}
