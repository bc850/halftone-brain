<?php

namespace App\Enums;

enum MondayOutcomeClassification: string
{
    case Success = 'success';
    case Retryable = 'retryable';
    case RateLimited = 'rate_limited';
    case BlockedConfiguration = 'blocked_configuration';
    case PermanentFailure = 'permanent_failure';
    case UncertainOutcome = 'uncertain_outcome';
}
