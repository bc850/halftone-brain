<?php

namespace App\Http\Controllers\Concerns;

use App\Support\Tax\InvalidTaxConfigurationException;
use App\Support\Tax\OverlappingTaxRateException;
use Illuminate\Validation\ValidationException;

/**
 * Tax configuration refusals are all statements about the values submitted: a rate
 * window that collides with an existing one, an edit that would rewrite history, a
 * certificate transition that is not legal. They surface as validation errors so
 * the form that produced them can show the reason.
 */
trait HandlesTaxConfiguration
{
    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $mutation
     * @return TReturn
     */
    protected function runTaxConfigurationMutation(callable $mutation, string $field = 'tax'): mixed
    {
        try {
            return $mutation();
        } catch (OverlappingTaxRateException|InvalidTaxConfigurationException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }
}
