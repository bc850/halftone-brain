<?php

namespace App\Support\Integrations\Monday;

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

    public static function rateLimited(string $message = 'Monday rate limit exceeded.'): self
    {
        return new self(new MondayProviderError('rate_limited', $message, retryable: true, httpStatus: 429));
    }

    public static function graphqlError(string $message = 'Monday GraphQL error.'): self
    {
        return new self(new MondayProviderError('graphql_error', $message, retryable: false));
    }

    public static function timeout(string $message = 'Monday request timed out.'): self
    {
        return new self(new MondayProviderError('timeout', $message, retryable: true));
    }

    public static function unauthorized(string $message = 'Monday authorization failed.'): self
    {
        return new self(new MondayProviderError('unauthorized', $message, retryable: false, httpStatus: 401));
    }

    public static function configuration(string $message = 'Monday configuration is invalid.'): self
    {
        return new self(new MondayProviderError('configuration_error', $message, retryable: false));
    }
}
