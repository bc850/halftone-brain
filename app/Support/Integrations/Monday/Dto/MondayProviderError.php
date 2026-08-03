<?php

namespace App\Support\Integrations\Monday\Dto;

use App\Enums\MondayOutcomeClassification;

/**
 * Bounded provider error information without raw GraphQL/HTTP bodies.
 */
final readonly class MondayProviderError
{
    public function __construct(
        public string $code,
        public string $message,
        public MondayOutcomeClassification $classification = MondayOutcomeClassification::PermanentFailure,
        public bool $retryable = false,
        public ?int $httpStatus = null,
        public ?int $retryAfterSeconds = null,
        public ?string $graphqlErrorCode = null,
    ) {}
}
