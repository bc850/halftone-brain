<?php

namespace App\Enums;

enum ItemKind: string
{
    case Product = 'product';
    case Material = 'material';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Product',
            self::Material => 'Material',
            self::Service => 'Service',
        };
    }
}
