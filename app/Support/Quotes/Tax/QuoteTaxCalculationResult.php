<?php

namespace App\Support\Quotes\Tax;

use App\Enums\QuoteTaxCalculationOutcome;
use App\Enums\QuoteTaxCalculationSource;
use App\Enums\QuoteTaxCalculationStatus;

/**
 * Immutable outcome of a tax calculation, ready to persist as a new calculation
 * version.
 */
final readonly class QuoteTaxCalculationResult
{
    /**
     * @param  list<string>  $reviewReasons  machine keys explaining a review_required outcome
     * @param  array<string, mixed>|null  $jurisdictionSnapshot
     */
    public function __construct(
        public QuoteTaxCalculationOutcome $outcome,
        public int $taxableBasisCents,
        public int $taxCents,
        public ?int $ratePpm,
        public QuoteTaxCalculationSource $source,
        public bool $isOverride,
        public ?string $overrideReason,
        public array $reviewReasons,
        public ?array $jurisdictionSnapshot,
        public ?int $certificateId,
        public string $calculatorVersion,
    ) {}

    public function revisionStatus(): QuoteTaxCalculationStatus
    {
        return $this->outcome->toRevisionStatus();
    }

    public function isResolved(): bool
    {
        return $this->outcome->isResolved();
    }
}
