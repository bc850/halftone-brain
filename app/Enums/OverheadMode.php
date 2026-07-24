<?php

namespace App\Enums;

enum OverheadMode: string
{
    case None = 'none';
    case Fixed = 'fixed';
    case Rate = 'rate';
}
