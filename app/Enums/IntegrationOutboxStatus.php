<?php

namespace App\Enums;

enum IntegrationOutboxStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Dispatched = 'dispatched';
    case Failed = 'failed';
    case Dead = 'dead';
}
