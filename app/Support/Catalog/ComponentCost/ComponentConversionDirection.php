<?php

namespace App\Support\Catalog\ComponentCost;

/**
 * How usage UOM mapped to purchase UOM for one component line.
 */
enum ComponentConversionDirection: string
{
    case Identical = 'identical';
    case Direct = 'direct';
    case Reciprocal = 'reciprocal';
}
