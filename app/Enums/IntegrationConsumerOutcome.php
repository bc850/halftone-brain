<?php

namespace App\Enums;

enum IntegrationConsumerOutcome: string
{
    case Succeeded = 'succeeded';
    case RetryableFailure = 'retryable_failure';
    case PermanentFailure = 'permanent_failure';
    case BlockedConfiguration = 'blocked_configuration';
    case Uncertain = 'uncertain';
}
