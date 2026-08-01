<?php

namespace App\Support\Integrations\Monday;

/**
 * Immutable configuration validation result (structural only in 2E.3A).
 */
final readonly class MondayConfigurationValidationResult
{
    /**
     * @param  list<string>  $errors
     */
    private function __construct(
        public bool $valid,
        public array $errors,
    ) {}

    public static function valid(): self
    {
        return new self(true, []);
    }

    /**
     * @param  list<string>  $errors
     */
    public static function invalid(array $errors): self
    {
        return new self(false, $errors);
    }
}
