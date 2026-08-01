<?php

namespace App\Support\Integrations\Monday\Dto;

/**
 * Bounded provider error information without raw GraphQL/HTTP bodies.
 */
final readonly class MondayProviderError
{
    public function __construct(
        public string $code,
        public string $message,
        public bool $retryable = false,
        public ?int $httpStatus = null,
    ) {}
}
