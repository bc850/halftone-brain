<?php

namespace App\Support\Integrations;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class StaleIntegrationSettingsException extends HttpException
{
    public function __construct(string $message = 'Integration settings are stale. Reload and try again.')
    {
        parent::__construct(409, $message);
    }
}
