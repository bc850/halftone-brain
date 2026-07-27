<?php

namespace App\Http\Requests\Concerns;

use App\Enums\QuoteAdjustmentMethod;
use App\Support\Money;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Adjustments persist a single `input_value` integer whose meaning follows the method:
 * cents for fixed amounts, basis points for percentages.
 */
trait NormalizesQuoteAdjustmentValue
{
    protected function adjustmentInputValue(QuoteAdjustmentMethod $method, mixed $value): int
    {
        try {
            return $method === QuoteAdjustmentMethod::Percentage
                ? Money::percentToBasisPoints((string) $value)
                : Money::dollarsToCents((string) $value);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['value' => $exception->getMessage()]);
        }
    }
}
