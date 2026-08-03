<?php

namespace App\Support\Integrations\Monday\Credentials;

/**
 * Immutable Monday credentials. Never log, audit, or serialize the token.
 */
final readonly class MondayCredentials
{
    public function __construct(
        private string $personalToken,
    ) {
        if (trim($this->personalToken) === '') {
            throw new \InvalidArgumentException('Monday personal token cannot be blank.');
        }
    }

    public function authorizationHeaderValue(): string
    {
        // Official Monday docs: Authorization header is the raw personal token (not Bearer).
        return $this->personalToken;
    }

    public function __debugInfo(): array
    {
        return ['personalToken' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Monday credentials must not be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Monday credentials must not be unserialized.');
    }
}
