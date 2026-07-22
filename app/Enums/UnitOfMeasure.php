<?php

namespace App\Enums;

enum UnitOfMeasure: string
{
    case Each = 'each';
    case SquareFoot = 'sq_ft';
    case LinearFoot = 'lin_ft';
    case Hour = 'hour';
    case Sheet = 'sheet';
    case Set = 'set';

    public function label(): string
    {
        return match ($this) {
            self::Each => 'Each',
            self::SquareFoot => 'Square foot',
            self::LinearFoot => 'Linear foot',
            self::Hour => 'Hour',
            self::Sheet => 'Sheet',
            self::Set => 'Set',
        };
    }
}
