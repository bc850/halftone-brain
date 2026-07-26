<?php

namespace App\Enums;

enum VendorProductOfferingStatus: string
{
    case Active = 'active';
    case Discontinued = 'discontinued';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Discontinued => 'Discontinued',
        };
    }
}
