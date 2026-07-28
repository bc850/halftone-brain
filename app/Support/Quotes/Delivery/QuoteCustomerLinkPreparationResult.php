<?php

namespace App\Support\Quotes\Delivery;

/**
 * One-time customer link material returned to an authenticated caller only.
 *
 * The raw token and URL must never be persisted, audited, logged, queued, or flashed.
 */
final readonly class QuoteCustomerLinkPreparationResult
{
    public function __construct(
        public string $rawCustomerUrl,
        public int $tokenId,
        public int $deliveryId,
        public int $documentId,
        public string $expiresAt,
    ) {}
}
