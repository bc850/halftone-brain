<?php

namespace App\Support\Quotes\Totals;

use App\Enums\QuoteLineDiscountMethod;
use App\Enums\QuoteLineType;

/**
 * Immutable per-line calculation result.
 */
final readonly class QuoteLineCalculationResult
{
    public function __construct(
        public string $key,
        public QuoteLineType $lineType,
        public int $quantityScaled,
        public ?int $unitPriceCents,
        public int $extendedPriceCents,
        public QuoteLineDiscountMethod $lineDiscountMethod,
        public int $lineDiscountValue,
        public int $lineDiscountAmountCents,
        public int $netLineTotalCents,
        public bool $isTaxable,
        public bool $isFinancial,
    ) {}
}
