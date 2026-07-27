<?php

namespace App\Support\Quotes\Totals;

use App\Enums\QuoteAdjustmentMethod;
use App\Enums\QuoteAdjustmentType;

/**
 * Immutable per-adjustment calculation result.
 */
final readonly class QuoteAdjustmentCalculationResult
{
    public function __construct(
        public string $key,
        public QuoteAdjustmentType $adjustmentType,
        public QuoteAdjustmentMethod $method,
        public int $inputValue,
        public int $amountCents,
        public bool $isTaxable,
        public bool $isDiscount,
        public bool $isPositiveCharge,
    ) {}
}
