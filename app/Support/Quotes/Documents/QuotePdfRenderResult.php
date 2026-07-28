<?php

namespace App\Support\Quotes\Documents;

/**
 * Rendered customer HTML and PDF bytes for a single generation attempt.
 */
final readonly class QuotePdfRenderResult
{
    public function __construct(
        public string $html,
        public string $pdf,
    ) {}
}
