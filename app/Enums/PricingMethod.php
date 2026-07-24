<?php

namespace App\Enums;

enum PricingMethod: string
{
    case Markup = 'markup';
    case TargetMargin = 'target_margin';
    case Fixed = 'fixed';
}
