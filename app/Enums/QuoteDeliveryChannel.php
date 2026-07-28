<?php

namespace App\Enums;

enum QuoteDeliveryChannel: string
{
    case Email = 'email';
    case Manual = 'manual';
}
