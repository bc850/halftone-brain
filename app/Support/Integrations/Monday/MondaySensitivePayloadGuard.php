<?php

namespace App\Support\Integrations\Monday;

use InvalidArgumentException;

/**
 * Fail-closed guard rejecting sensitive keys from Monday DTOs and mapping inputs.
 */
final class MondaySensitivePayloadGuard
{
    /**
     * @var list<string>
     */
    public const FORBIDDEN_KEYS = [
        'api_token',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'authorization_header',
        'client_secret',
        'cookie',
        'cookies',
        'customer_access_token',
        'customer_access_token_hash',
        'private_document_path',
        'document_path',
        'certificate_number',
        'certificate_evidence',
        'customer_response_ip',
        'employee_response_reason',
        'cost',
        'margin',
        'markup',
        'component_pricing',
        'pricing_internals',
        'approval_reasoning',
        'internal_notes',
        'request_body',
        'response_body',
        'payload',
        'full_payload',
        'graphql_errors',
        'raw_headers',
        'requested_due_date',
        'expiration_date',
    ];

    /**
     * @param  array<mixed, mixed>  $payload
     */
    public static function assertNoSensitiveKeys(array $payload, string $path = ''): void
    {
        foreach ($payload as $key => $value) {
            $keyString = (string) $key;
            $currentPath = $path === '' ? $keyString : $path.'.'.$keyString;

            if (self::isForbiddenKey($keyString)) {
                throw new InvalidArgumentException("Sensitive or forbidden key [{$currentPath}] is not permitted in Monday contracts.");
            }

            if (is_array($value)) {
                self::assertNoSensitiveKeys($value, $currentPath);
            }
        }
    }

    public static function isForbiddenKey(string $key): bool
    {
        $normalized = strtolower($key);

        if (in_array($normalized, self::FORBIDDEN_KEYS, true)) {
            return true;
        }

        return str_contains($normalized, 'token')
            || str_contains($normalized, 'secret')
            || str_contains($normalized, 'authorization')
            || str_contains($normalized, 'password')
            || str_contains($normalized, 'cookie');
    }
}
