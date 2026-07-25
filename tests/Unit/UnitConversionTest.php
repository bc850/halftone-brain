<?php

use App\Enums\UnitOfMeasure;
use App\Support\Catalog\UnitConversion;

test('unit conversion multiplies and divides with exact integer ratios', function () {
    $conversion = new UnitConversion(UnitOfMeasure::Sheet, UnitOfMeasure::SquareFoot, 32, 1);

    expect($conversion->convert('1', 0))->toBe('32')
        ->and($conversion->convert('0.5', 4))->toBe('16.0000')
        ->and($conversion->convert('3', 2))->toBe('96.00');
});

test('unit conversion rejects zero negative and float-unsafe inputs', function () {
    expect(fn () => new UnitConversion(UnitOfMeasure::Sheet, UnitOfMeasure::SquareFoot, 0, 1))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new UnitConversion(UnitOfMeasure::Sheet, UnitOfMeasure::SquareFoot, 32, 0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new UnitConversion(UnitOfMeasure::Sheet, UnitOfMeasure::SquareFoot, -5, 1))
        ->toThrow(InvalidArgumentException::class);

    $conversion = new UnitConversion(UnitOfMeasure::Yard, UnitOfMeasure::Foot, 3, 1);

    expect(fn () => $conversion->convert('-1'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $conversion->convert('1e2'))
        ->toThrow(InvalidArgumentException::class);
});

test('unit of measure retains legacy values and adds new ones', function () {
    expect(UnitOfMeasure::Each->value)->toBe('each')
        ->and(UnitOfMeasure::Sheet->value)->toBe('sheet')
        ->and(UnitOfMeasure::SquareFoot->value)->toBe('sq_ft')
        ->and(UnitOfMeasure::LinearFoot->value)->toBe('lin_ft')
        ->and(UnitOfMeasure::Foot->value)->toBe('foot')
        ->and(UnitOfMeasure::Inch->value)->toBe('inch')
        ->and(UnitOfMeasure::Roll->value)->toBe('roll')
        ->and(UnitOfMeasure::Yard->value)->toBe('yard')
        ->and(UnitOfMeasure::SquareYard->value)->toBe('sq_yd')
        ->and(UnitOfMeasure::Thousand->value)->toBe('thousand');
});
