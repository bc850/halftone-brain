<?php

namespace App\Support\Integrations\Monday;

use App\Enums\MondayOutcomeClassification;
use App\Support\Integrations\Monday\Dto\MondayProviderError;
use App\Support\Integrations\Outbox\IntegrationErrorSanitizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Central Monday error classifier producing provider-neutral outcomes.
 */
final class MondayErrorClassifier
{
    public function __construct(
        private IntegrationErrorSanitizer $sanitizer,
    ) {}

    public function fromHttpResponse(Response $response, bool $requestLikelyTransmitted = true): MondayProviderError
    {
        $status = $response->status();
        $retryAfter = $this->parseRetryAfterSeconds($response);
        $graphqlCode = $this->firstGraphqlErrorCode($response);

        if ($status === 429) {
            return new MondayProviderError(
                code: 'rate_limited',
                message: $this->sanitizer->message('Monday rate limit exceeded.') ?? 'Monday rate limit exceeded.',
                classification: MondayOutcomeClassification::RateLimited,
                retryable: true,
                httpStatus: 429,
                retryAfterSeconds: $retryAfter,
                graphqlErrorCode: $graphqlCode,
            );
        }

        if (in_array($status, [401, 403], true)) {
            return new MondayProviderError(
                code: 'unauthorized',
                message: $this->sanitizer->message('Monday authorization failed.') ?? 'Monday authorization failed.',
                classification: MondayOutcomeClassification::BlockedConfiguration,
                retryable: false,
                httpStatus: $status,
                graphqlErrorCode: $graphqlCode,
            );
        }

        if ($status === 404) {
            return new MondayProviderError(
                code: 'not_found',
                message: $this->sanitizer->message('Monday resource was not found.') ?? 'Monday resource was not found.',
                classification: MondayOutcomeClassification::BlockedConfiguration,
                retryable: false,
                httpStatus: 404,
                graphqlErrorCode: $graphqlCode,
            );
        }

        if ($status === 409) {
            return new MondayProviderError(
                code: 'idempotency_conflict',
                message: $this->sanitizer->message('Monday idempotency conflict.') ?? 'Monday idempotency conflict.',
                classification: MondayOutcomeClassification::Retryable,
                retryable: true,
                httpStatus: 409,
                retryAfterSeconds: $retryAfter ?? 5,
                graphqlErrorCode: $graphqlCode,
            );
        }

        if ($status >= 500) {
            return new MondayProviderError(
                code: 'server_error',
                message: $this->sanitizer->message('Monday server error.') ?? 'Monday server error.',
                classification: MondayOutcomeClassification::Retryable,
                retryable: true,
                httpStatus: $status,
                graphqlErrorCode: $graphqlCode,
            );
        }

        if ($status === 200 && $this->responseHasGraphqlErrors($response)) {
            return $this->fromGraphqlErrors($response, $retryAfter);
        }

        return new MondayProviderError(
            code: 'http_error',
            message: $this->sanitizer->message('Monday HTTP request failed.') ?? 'Monday HTTP request failed.',
            classification: MondayOutcomeClassification::PermanentFailure,
            retryable: false,
            httpStatus: $status,
            graphqlErrorCode: $graphqlCode,
        );
    }

    public function fromTransport(Throwable $exception, bool $requestLikelyTransmitted): MondayProviderError
    {
        $message = $this->sanitizer->message('Monday transport failure.') ?? 'Monday transport failure.';

        if ($exception instanceof ConnectionException) {
            if ($requestLikelyTransmitted) {
                return new MondayProviderError(
                    code: 'uncertain_timeout',
                    message: $this->sanitizer->message('Monday request timed out after possible transmission.') ?? $message,
                    classification: MondayOutcomeClassification::UncertainOutcome,
                    retryable: false,
                );
            }

            return new MondayProviderError(
                code: 'transport_error',
                message: $this->sanitizer->message('Monday connection failed before transmission.') ?? $message,
                classification: MondayOutcomeClassification::Retryable,
                retryable: true,
            );
        }

        return new MondayProviderError(
            code: 'transport_error',
            message: $message,
            classification: MondayOutcomeClassification::Retryable,
            retryable: true,
        );
    }

    public function malformedResponse(string $code = 'malformed_response'): MondayProviderError
    {
        return new MondayProviderError(
            code: $code,
            message: $this->sanitizer->message('Monday response was malformed.') ?? 'Monday response was malformed.',
            classification: MondayOutcomeClassification::PermanentFailure,
            retryable: false,
        );
    }

    public function oversizedResponse(): MondayProviderError
    {
        return new MondayProviderError(
            code: 'oversized_response',
            message: $this->sanitizer->message('Monday response exceeded the approved size bound.') ?? 'Monday response exceeded the approved size bound.',
            classification: MondayOutcomeClassification::PermanentFailure,
            retryable: false,
        );
    }

    public function clientNotConfigured(): MondayProviderError
    {
        return new MondayProviderError(
            code: 'client_not_configured',
            message: $this->sanitizer->message('Monday API client is not configured.') ?? 'Monday API client is not configured.',
            classification: MondayOutcomeClassification::BlockedConfiguration,
            retryable: false,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     */
    public function fromGraphqlErrorList(array $errors, ?int $retryAfter = null): MondayProviderError
    {
        $first = $errors[0] ?? [];
        $stableCode = null;

        if (isset($first['extensions']) && is_array($first['extensions'])) {
            $stableCode = isset($first['extensions']['code']) && is_string($first['extensions']['code'])
                ? $first['extensions']['code']
                : (isset($first['extensions']['error_code']) && is_string($first['extensions']['error_code'])
                    ? $first['extensions']['error_code']
                    : null);
        }

        if ($stableCode === null && isset($first['status_code']) && is_numeric($first['status_code'])) {
            // fall through to status-based mapping below
        }

        $retryIn = null;
        if (isset($first['extensions']) && is_array($first['extensions']) && isset($first['extensions']['retry_in_seconds'])) {
            $retryIn = (int) $first['extensions']['retry_in_seconds'];
        }

        $normalized = strtoupper((string) ($stableCode ?? ''));

        if (in_array($normalized, ['COMPLEXITYEXCEPTION', 'RATE_LIMIT_EXCEEDED', 'MINUTE_LIMIT_EXCEEDED', 'IP_RATE_LIMIT_EXCEEDED', 'DAILY_LIMIT_EXCEEDED', 'CONCURRENCY_LIMIT_EXCEEDED'], true)) {
            return new MondayProviderError(
                code: 'rate_limited',
                message: $this->sanitizer->message('Monday rate limit exceeded.') ?? 'Monday rate limit exceeded.',
                classification: MondayOutcomeClassification::RateLimited,
                retryable: true,
                httpStatus: 429,
                retryAfterSeconds: $retryIn ?? $retryAfter,
                graphqlErrorCode: $stableCode,
            );
        }

        if (in_array($normalized, ['USERUNAUTHORIZEDEXCEPTION', 'UNAUTHORIZED', 'FORBIDDEN', 'AUTHENTICATEDEXCEPTION'], true)) {
            return new MondayProviderError(
                code: 'unauthorized',
                message: $this->sanitizer->message('Monday authorization failed.') ?? 'Monday authorization failed.',
                classification: MondayOutcomeClassification::BlockedConfiguration,
                retryable: false,
                httpStatus: 403,
                graphqlErrorCode: $stableCode,
            );
        }

        if (in_array($normalized, ['INVALIDBOARDEXCEPTION', 'INVALIDCOLUMNDIDEXCEPTION', 'INVALIDCOLUMNIDEXCEPTION', 'RESOURCENOTFOUNDEXCEPTION', 'ITEMNAMEEXCEPTION'], true)
            || str_contains($normalized, 'INVALID')
            || str_contains($normalized, 'NOTFOUND')) {
            return new MondayProviderError(
                code: 'blocked_configuration',
                message: $this->sanitizer->message('Monday configuration is invalid for this board or column.') ?? 'Monday configuration is invalid for this board or column.',
                classification: MondayOutcomeClassification::BlockedConfiguration,
                retryable: false,
                graphqlErrorCode: $stableCode,
            );
        }

        if ($normalized === '' || $normalized === 'UNKNOWN') {
            return new MondayProviderError(
                code: 'unknown_graphql_error',
                message: $this->sanitizer->message('Monday returned an unclassified GraphQL error.') ?? 'Monday returned an unclassified GraphQL error.',
                classification: MondayOutcomeClassification::PermanentFailure,
                retryable: false,
                graphqlErrorCode: $stableCode,
            );
        }

        return new MondayProviderError(
            code: 'graphql_error',
            message: $this->sanitizer->message('Monday GraphQL error.') ?? 'Monday GraphQL error.',
            classification: MondayOutcomeClassification::PermanentFailure,
            retryable: false,
            retryAfterSeconds: $retryIn ?? $retryAfter,
            graphqlErrorCode: $stableCode,
        );
    }

    private function fromGraphqlErrors(Response $response, ?int $retryAfter): MondayProviderError
    {
        $json = $response->json();
        $errors = is_array($json) && isset($json['errors']) && is_array($json['errors']) ? $json['errors'] : [];

        /** @var array<int, array<string, mixed>> $errors */
        return $this->fromGraphqlErrorList($errors, $retryAfter);
    }

    private function responseHasGraphqlErrors(Response $response): bool
    {
        $json = $response->json();

        return is_array($json) && isset($json['errors']) && is_array($json['errors']) && $json['errors'] !== [];
    }

    private function firstGraphqlErrorCode(Response $response): ?string
    {
        $json = $response->json();
        if (! is_array($json) || ! isset($json['errors'][0]) || ! is_array($json['errors'][0])) {
            return null;
        }

        $first = $json['errors'][0];
        if (isset($first['extensions']) && is_array($first['extensions'])) {
            if (isset($first['extensions']['code']) && is_string($first['extensions']['code'])) {
                return $first['extensions']['code'];
            }
            if (isset($first['extensions']['error_code']) && is_string($first['extensions']['error_code'])) {
                return $first['extensions']['error_code'];
            }
        }

        return null;
    }

    private function parseRetryAfterSeconds(Response $response): ?int
    {
        $header = $response->header('Retry-After');
        if (is_numeric($header)) {
            return max(0, (int) $header);
        }

        $json = $response->json();
        if (is_array($json) && isset($json['errors'][0]['extensions']['retry_in_seconds'])) {
            return max(0, (int) $json['errors'][0]['extensions']['retry_in_seconds']);
        }

        return null;
    }
}
