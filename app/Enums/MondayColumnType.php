<?php

namespace App\Enums;

/**
 * Approved Monday column types for v1 intake mapping validation.
 */
enum MondayColumnType: string
{
    case Text = 'text';
    case LongText = 'long_text';
    case Numbers = 'numbers';
    case Date = 'date';
    case Status = 'status';
    case Link = 'link';
    case People = 'people';
}
