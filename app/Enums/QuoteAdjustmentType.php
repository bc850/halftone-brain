<?php

namespace App\Enums;

enum QuoteAdjustmentType: string
{
    case QuoteDiscount = 'quote_discount';
    case Fee = 'fee';
    case Shipping = 'shipping';
    case Installation = 'installation';
    case Other = 'other';

    public function isDiscount(): bool
    {
        return $this === self::QuoteDiscount;
    }

    public function isPositiveCharge(): bool
    {
        return ! $this->isDiscount();
    }
}
