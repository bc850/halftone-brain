<?php

namespace App\Enums;

enum QuoteLifecycleStatus: string
{
    case Open = 'open';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
            self::Void => 'Void',
        };
    }

    public function isTerminal(): bool
    {
        return $this !== self::Open;
    }
}
