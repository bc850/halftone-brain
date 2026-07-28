<?php

namespace App\Support\Quotes\Security;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * Pure helpers for customer quote access tokens.
 *
 * Raw tokens are cryptographically random base64url strings returned once to a
 * future creation workflow. Only the SHA-256 hex digest is ever persisted.
 */
final class QuoteCustomerAccessTokenGenerator
{
    public function generateRaw(int $bytes = 32): string
    {
        if ($bytes < 16) {
            throw new InvalidArgumentException('Customer access tokens require at least 16 random bytes.');
        }

        return $this->base64UrlEncode(random_bytes($bytes));
    }

    public function hashToken(string $rawToken): string
    {
        if ($rawToken === '') {
            throw new InvalidArgumentException('Cannot hash an empty customer access token.');
        }

        return hash('sha256', $rawToken);
    }

    public function verify(string $rawToken, string $tokenHash): bool
    {
        if ($rawToken === '' || $tokenHash === '') {
            return false;
        }

        return hash_equals($tokenHash, $this->hashToken($rawToken));
    }

    public function isExpired(DateTimeInterface $expiresAt, ?DateTimeInterface $at = null): bool
    {
        $now = $at ?? now();

        return $expiresAt->getTimestamp() <= $now->getTimestamp();
    }

    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
