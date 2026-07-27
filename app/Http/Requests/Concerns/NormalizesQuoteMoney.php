<?php

namespace App\Http\Requests\Concerns;

use App\Support\Money;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

trait NormalizesQuoteMoney
{
    /**
     * Validation rule for a customer-facing dollar amount with at most two decimals.
     *
     * @return list<string>
     */
    protected function dollarAmountRules(): array
    {
        return ['regex:/^\d+(\.\d{1,2})?$/'];
    }

    /**
     * Validation rule for a six-decimal quantity string.
     *
     * @return list<string>
     */
    protected function quantityRules(): array
    {
        return ['regex:/^\d+(\.\d{1,6})?$/'];
    }

    protected function centsFromDollars(mixed $dollars, string $field): ?int
    {
        if ($dollars === null || $dollars === '') {
            return null;
        }

        try {
            return Money::dollarsToCents((string) $dollars);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }
}
