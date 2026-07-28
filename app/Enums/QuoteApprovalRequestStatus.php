<?php

namespace App\Enums;

enum QuoteApprovalRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    public function isResolved(): bool
    {
        return $this !== self::Pending;
    }
}
