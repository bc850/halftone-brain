<?php

namespace App\Support\Quotes\Totals;

use App\Enums\QuoteAdjustmentMethod;
use App\Enums\QuoteAdjustmentType;
use InvalidArgumentException;

/**
 * Immutable adjustment input for pure totals calculation.
 */
final readonly class QuoteAdjustmentCalculationInput
{
    /**
     * @param  list<string>|null  $approvalReasons
     */
    public function __construct(
        public string $key,
        public QuoteAdjustmentType $adjustmentType,
        public string $descriptionSnapshot,
        public QuoteAdjustmentMethod $method,
        public int $inputValue,
        public bool $isTaxable,
        public bool $approvalRequired,
        public ?array $approvalReasons = null,
    ) {
        if ($this->key === '') {
            throw new InvalidArgumentException('Adjustment key is required.');
        }
    }
}
