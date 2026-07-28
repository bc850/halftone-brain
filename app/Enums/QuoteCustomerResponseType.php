<?php

namespace App\Enums;

enum QuoteCustomerResponseType: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function requiresTermsAndTypedName(): bool
    {
        return $this === self::Accepted;
    }
}
