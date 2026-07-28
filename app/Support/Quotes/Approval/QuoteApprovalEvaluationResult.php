<?php

namespace App\Support\Quotes\Approval;

/**
 * Why a quote does or does not need approval.
 *
 * `reasons` are stable machine keys; `explanations` maps each key to a sentence
 * safe to show an internal user. Neither exposes cost, margin, or actor detail.
 */
final readonly class QuoteApprovalEvaluationResult
{
    /**
     * @param  list<string>  $reasons
     * @param  array<string, string>  $explanations
     */
    public function __construct(
        public bool $requiresApproval,
        public array $reasons,
        public array $explanations,
        public int $thresholdBasisCents,
        public bool $meetsMonetaryThreshold,
    ) {}

    public function hasReason(string $reason): bool
    {
        return in_array($reason, $this->reasons, true);
    }
}
