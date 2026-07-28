<?php

namespace App\Enums;

enum QuoteApprovalDecisionType: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * A rejection must explain itself; an approval may stand on its own.
     */
    public function requiresReason(): bool
    {
        return $this === self::Rejected;
    }

    public function toRequestStatus(): QuoteApprovalRequestStatus
    {
        return match ($this) {
            self::Approved => QuoteApprovalRequestStatus::Approved,
            self::Rejected => QuoteApprovalRequestStatus::Rejected,
        };
    }
}
