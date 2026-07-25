<?php

namespace App\Enums;

enum UnitOfMeasure: string
{
    case Each = 'each';
    case Sheet = 'sheet';
    case SquareFoot = 'sq_ft';
    case LinearFoot = 'lin_ft';
    case Foot = 'foot';
    case Inch = 'inch';
    case Roll = 'roll';
    case Yard = 'yard';
    case SquareYard = 'sq_yd';
    case Hour = 'hour';
    case Set = 'set';
    case Thousand = 'thousand';

    public function label(): string
    {
        return match ($this) {
            self::Each => 'Each',
            self::Sheet => 'Sheet',
            self::SquareFoot => 'Square foot',
            self::LinearFoot => 'Linear foot',
            self::Foot => 'Foot',
            self::Inch => 'Inch',
            self::Roll => 'Roll',
            self::Yard => 'Yard',
            self::SquareYard => 'Square yard',
            self::Hour => 'Hour',
            self::Set => 'Set',
            self::Thousand => 'Thousand',
        };
    }
}
