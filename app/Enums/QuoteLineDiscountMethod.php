<?php

namespace App\Enums;

enum QuoteLineDiscountMethod: string
{
    case None = 'none';
    case Fixed = 'fixed';
    case Percentage = 'percentage';
}
