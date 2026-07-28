<?php

namespace App\Support\Tax;

/**
 * Result of checking whether one certificate supports exemption for a sale.
 *
 * When `isApplicable` is false the `reasons` explain why, and the caller must
 * treat the sale as needing review — never as silently exempt.
 */
final readonly class TaxCertificateApplicability
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public bool $isApplicable,
        public array $reasons,
        public ?int $certificateId = null,
    ) {}

    public function requiresReview(): bool
    {
        return ! $this->isApplicable;
    }
}
