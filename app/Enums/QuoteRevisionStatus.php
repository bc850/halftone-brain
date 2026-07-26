<?php

namespace App\Enums;

enum QuoteRevisionStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Sent = 'sent';
    case Viewed = 'viewed';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Superseded = 'superseded';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
            self::Sent => 'Sent',
            self::Viewed => 'Viewed',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
            self::Superseded => 'Superseded',
            self::Void => 'Void',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Accepted,
            self::Rejected,
            self::Expired,
            self::Superseded,
            self::Void,
        ], true);
    }

    public function isCustomerContentImmutable(): bool
    {
        return in_array($this, [
            self::Sent,
            self::Viewed,
            self::Accepted,
            self::Rejected,
            self::Expired,
            self::Superseded,
            self::Void,
        ], true);
    }

    public function isCustomerVisible(): bool
    {
        return in_array($this, [
            self::Sent,
            self::Viewed,
            self::Accepted,
            self::Rejected,
            self::Expired,
        ], true);
    }
}
