<?php

namespace App\Enums;

enum IntegrationValidationStatus: string
{
    case NeverValidated = 'never_validated';
    case Valid = 'valid';
    case Invalid = 'invalid';
    case ClientNotConfigured = 'client_not_configured';
}
