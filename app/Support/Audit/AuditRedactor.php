<?php

namespace App\Support\Audit;

final class AuditRedactor
{
    /**
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'api_key',
        'apikey',
        'secret',
        'client_secret',
        'access_token',
        'refresh_token',
        'id_token',
        'authorization',
        'oauth',
        'oauth_token',
        'stripe_secret',
        'stripe_key',
        'payment_method',
        'payment_intent',
        'card_number',
        'cvc',
        'public_quote_token',
        'quote_token',
        'private_key',
        'session',
        'session_payload',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'recovery_codes',
        'remember_token',
        'token',
    ];

    public function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $redacted = [];

            foreach ($value as $key => $item) {
                if (is_string($key) && $this->isSensitiveKey($key)) {
                    $redacted[$key] = '[REDACTED]';

                    continue;
                }

                $redacted[$key] = $this->redact($item);
            }

            return $redacted;
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($normalized === $sensitive || str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
