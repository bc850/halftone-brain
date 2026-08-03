<?php

namespace App\Support\Integrations\Monday\Credentials;

interface MondayCredentialsProviderInterface
{
    /**
     * Return credentials when configured, otherwise null (fail closed).
     */
    public function get(): ?MondayCredentials;
}
