<?php

namespace App\Enums;

/**
 * Outcome recorded on a persisted tax calculation row.
 *
 * There is deliberately no "pending" case: a calculation row only exists once a
 * calculation ran. `QuoteTaxCalculationStatus` keeps the denormalized revision
 * status, which can still be pending before any calculation exists.
 */
enum QuoteTaxCalculationOutcome: string
{
    case Calculated = 'calculated';
    case Exempt = 'exempt';
    case ReviewRequired = 'review_required';

    public function isResolved(): bool
    {
        return match ($this) {
            self::Calculated, self::Exempt => true,
            self::ReviewRequired => false,
        };
    }

    public function toRevisionStatus(): QuoteTaxCalculationStatus
    {
        return match ($this) {
            self::Calculated => QuoteTaxCalculationStatus::Calculated,
            self::Exempt => QuoteTaxCalculationStatus::Exempt,
            self::ReviewRequired => QuoteTaxCalculationStatus::ReviewRequired,
        };
    }
}
