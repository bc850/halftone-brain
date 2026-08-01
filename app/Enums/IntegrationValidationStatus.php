<?php

namespace App\Enums;

enum IntegrationValidationStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
}
