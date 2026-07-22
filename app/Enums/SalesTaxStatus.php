<?php

namespace App\Enums;

enum SalesTaxStatus: string
{
    case Taxable = 'taxable';
    case Exempt = 'exempt';

    public function label(): string
    {
        return match ($this) {
            self::Taxable => 'Taxable',
            self::Exempt => 'Exempt',
        };
    }
}
