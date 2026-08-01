<?php

namespace App\Support\Integrations\Outbox;

use App\Support\Audit\AuditRedactor;

/**
 * Centralized sanitizer for integration error codes/messages and provider refs.
 */
final class IntegrationErrorSanitizer
{
    /**
     * @var list<string>
     */
    private const PROVIDER_REFERENCE_ALLOWED_KEYS = [
        'provider',
        'resource_type',
        'remote_id',
        'remote_board_id',
        'remote_url',
        'provider_request_id',
        'idempotency_replayed',
        'api_version',
        'idempotency_key',
    ];

    public function __construct(
        private AuditRedactor $redactor,
    ) {}

    public function code(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $trimmed = trim($code);

        if ($trimmed === '') {
            return null;
        }

        $safe = preg_replace('/[^a-zA-Z0-9._:-]/', '_', $trimmed) ?? 'invalid_error_code';

        return substr($safe, 0, 80);
    }

    public function message(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $max = 500;

        if (function_exists('config')) {
            try {
                $configured = config('integrations.errors.max_message_length', 500);
                if (is_numeric($configured)) {
                    $max = (int) $configured;
                }
            } catch (\Throwable) {
                $max = 500;
            }
        }
        $cleaned = $this->stripSecretsFromText($message);
        $cleaned = preg_replace('/\s+/', ' ', $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned);

        if ($cleaned === '') {
            return null;
        }

        return substr($cleaned, 0, max(1, $max));
    }

    /**
     * @param  array<string, mixed>|null  $reference
     * @return array<string, mixed>|null
     */
    public function providerReference(?array $reference): ?array
    {
        if ($reference === null) {
            return null;
        }

        $allowed = self::PROVIDER_REFERENCE_ALLOWED_KEYS;

        $filtered = [];

        foreach ($reference as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }

            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            if (is_string($value)) {
                $value = $this->stripSecretsFromText($value);
                $value = substr($value, 0, 191);
            }

            $filtered[$key] = $value;
        }

        /** @var array<string, mixed> $redacted */
        $redacted = $this->redactor->redact($filtered);

        return $redacted === [] ? null : $redacted;
    }

    public function stripSecretsFromText(string $text): string
    {
        $patterns = [
            '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i',
            '/(?i)(authorization|api[_-]?key|token|secret|password|cookie)\s*[:=]\s*\S+/',
            '/https?:\/\/[^\s]+:[^\s]+@[^\s]+/i',
            '/\b[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/',
        ];

        $cleaned = $text;

        foreach ($patterns as $pattern) {
            $cleaned = preg_replace($pattern, '[REDACTED]', $cleaned) ?? $cleaned;
        }

        return $cleaned;
    }
}
