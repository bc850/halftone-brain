<?php

namespace App\Enums;

enum QuoteLineType: string
{
    case Catalog = 'catalog';
    case Custom = 'custom';
    case Section = 'section';
    case Note = 'note';

    public function isFinancial(): bool
    {
        return match ($this) {
            self::Catalog, self::Custom => true,
            self::Section, self::Note => false,
        };
    }
}
