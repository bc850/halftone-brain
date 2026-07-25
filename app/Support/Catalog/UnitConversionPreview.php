<?php

namespace App\Support\Catalog;

use App\Enums\UnitOfMeasure;
use InvalidArgumentException;

/**
 * Exact conversion preview text using BCMath integer ratios (no floats).
 */
final class UnitConversionPreview
{
    /**
     * @return array{preview: string, derived_reciprocal: string|null, converted_one: numeric-string}
     */
    public static function make(string $fromUnit, string $toUnit, int $numerator, int $denominator): array
    {
        if ($fromUnit === $toUnit) {
            throw new InvalidArgumentException('From unit and to unit must be different.');
        }

        if ($numerator < 1 || $denominator < 1) {
            throw new InvalidArgumentException('Conversion ratio must be greater than zero.');
        }

        $from = UnitOfMeasure::from($fromUnit);
        $to = UnitOfMeasure::from($toUnit);
        $conversion = new UnitConversion($from, $to, $numerator, $denominator);
        $convertedOne = $conversion->convert('1', 8);
        $trimmed = self::trimDecimal($convertedOne);

        $preview = sprintf(
            '1 %s = %s %s',
            $from->label(),
            $trimmed,
            $to->label(),
        );

        $reciprocal = new UnitConversion($to, $from, $denominator, $numerator);
        $back = self::trimDecimal($reciprocal->convert('1', 8));
        $derived = sprintf(
            'Derived reciprocal (not stored): 1 %s = %s %s',
            $to->label(),
            $back,
            $from->label(),
        );

        return [
            'preview' => $preview,
            'derived_reciprocal' => $derived,
            'converted_one' => $convertedOne,
        ];
    }

    private static function trimDecimal(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
