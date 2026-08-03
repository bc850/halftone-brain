<?php

namespace App\Support\Integrations\Monday\Credentials;

/**
 * Explicitly unavailable credentials (tests / fail-closed rehearsal).
 */
final class UnavailableMondayCredentialsProvider implements MondayCredentialsProviderInterface
{
    public function get(): ?MondayCredentials
    {
        return null;
    }
}
