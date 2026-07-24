<?php

namespace App\Enums;

enum ProductFamily: string
{
    case Signage = 'signage';
    case Apparel = 'apparel';
    case Embroidery = 'embroidery';
    case Promotional = 'promotional';
    case Service = 'service';
    case Other = 'other';
}
