<?php

namespace App\Enums;

enum IntegrationOutboxDeliveryStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Retrying = 'retrying';
    case Succeeded = 'succeeded';
    case BlockedConfiguration = 'blocked_configuration';
    case Failed = 'failed';
    case Dead = 'dead';
    case Abandoned = 'abandoned';
}
