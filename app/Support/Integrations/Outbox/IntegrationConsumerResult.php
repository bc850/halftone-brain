<?php

namespace App\Support\Integrations\Outbox;

use App\Enums\IntegrationConsumerOutcome;

final class IntegrationConsumerResult
{
    /**
     * @param  array<string, mixed>|null  $providerReference
     */
    private function __construct(
        public IntegrationConsumerOutcome $outcome,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?array $providerReference = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $providerReference
     */
    public static function succeeded(?array $providerReference = null): self
    {
        return new self(IntegrationConsumerOutcome::Succeeded, providerReference: $providerReference);
    }

    public static function retryable(string $code, string $message): self
    {
        return new self(IntegrationConsumerOutcome::RetryableFailure, $code, $message);
    }

    public static function permanent(string $code, string $message): self
    {
        return new self(IntegrationConsumerOutcome::PermanentFailure, $code, $message);
    }

    public static function blockedConfiguration(string $code, string $message): self
    {
        return new self(IntegrationConsumerOutcome::BlockedConfiguration, $code, $message);
    }

    public static function uncertain(string $code, string $message): self
    {
        return new self(IntegrationConsumerOutcome::Uncertain, $code, $message);
    }
}
