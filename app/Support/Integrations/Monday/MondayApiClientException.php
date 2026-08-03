<?php

namespace App\Support\Integrations\Monday;

use App\Enums\MondayOutcomeClassification;
use App\Support\Integrations\Monday\Dto\MondayProviderError;
use RuntimeException;

/**
 * Bounded Monday client failure without raw HTTP/GraphQL payloads.
 */
final class MondayApiClientException extends RuntimeException
{
    public function __construct(
        public readonly MondayProviderError $error,
    ) {
        parent::__construct($error->message);
    }

    public static function fromError(MondayProviderError $error): self
    {
        return new self($error);
    }

    public static function rateLimited(
        string $message = 'Monday rate limit exceeded.',
        ?int $retryAfterSeconds = null,
    ): self {
        return new self(new MondayProviderError(
            code: 'rate_limited',
            message: $message,
            classification: MondayOutcomeClassification::RateLimited,
            retryable: true,
            httpStatus: 429,
            retryAfterSeconds: $retryAfterSeconds,
        ));
    }

    public static function graphqlError(string $message = 'Monday GraphQL error.'): self
    {
        return new self(new MondayProviderError(
            code: 'graphql_error',
            message: $message,
            classification: MondayOutcomeClassification::PermanentFailure,
            retryable: false,
        ));
    }

    public static function timeout(
        string $message = 'Monday request timed out.',
        bool $uncertain = false,
    ): self {
        return new self(new MondayProviderError(
            code: $uncertain ? 'uncertain_timeout' : 'timeout',
            message: $message,
            classification: $uncertain
                ? MondayOutcomeClassification::UncertainOutcome
                : MondayOutcomeClassification::Retryable,
            retryable: ! $uncertain,
        ));
    }

    public static function unauthorized(string $message = 'Monday authorization failed.'): self
    {
        return new self(new MondayProviderError(
            code: 'unauthorized',
            message: $message,
            classification: MondayOutcomeClassification::BlockedConfiguration,
            retryable: false,
            httpStatus: 401,
        ));
    }

    public static function configuration(
        string $message = 'Monday configuration is invalid.',
        string $code = 'configuration_error',
    ): self {
        return new self(new MondayProviderError(
            code: $code,
            message: $message,
            classification: MondayOutcomeClassification::BlockedConfiguration,
            retryable: false,
        ));
    }

    public static function clientNotConfigured(string $message = 'Monday API client is not configured.'): self
    {
        return self::configuration($message, 'client_not_configured');
    }

    public static function retryable(string $code, string $message, ?int $httpStatus = null): self
    {
        return new self(new MondayProviderError(
            code: $code,
            message: $message,
            classification: MondayOutcomeClassification::Retryable,
            retryable: true,
            httpStatus: $httpStatus,
        ));
    }

    public static function permanent(string $code, string $message, ?int $httpStatus = null): self
    {
        return new self(new MondayProviderError(
            code: $code,
            message: $message,
            classification: MondayOutcomeClassification::PermanentFailure,
            retryable: false,
            httpStatus: $httpStatus,
        ));
    }

    public static function uncertain(string $code, string $message): self
    {
        return new self(new MondayProviderError(
            code: $code,
            message: $message,
            classification: MondayOutcomeClassification::UncertainOutcome,
            retryable: false,
        ));
    }
}
