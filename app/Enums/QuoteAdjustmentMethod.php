<?php

namespace App\Enums;

enum QuoteAdjustmentMethod: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';
}
