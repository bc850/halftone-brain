<?php

namespace App\Support\Integrations\Monday\Credentials;

/**
 * V1 environment/secret-backed personal-token credentials.
 */
final class EnvMondayCredentialsProvider implements MondayCredentialsProviderInterface
{
    public function get(): ?MondayCredentials
    {
        $token = config('services.monday.personal_token');

        if (! is_string($token)) {
            return null;
        }

        $token = trim($token);

        if ($token === '') {
            return null;
        }

        return new MondayCredentials($token);
    }
}
