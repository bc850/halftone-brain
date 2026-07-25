<?php

use App\Enums\UnitOfMeasure;
use App\Support\Catalog\UnitConversionPreview;

test('unit conversion preview formats exact sheet to square foot ratio', function () {
    $preview = UnitConversionPreview::make(
        UnitOfMeasure::Sheet->value,
        UnitOfMeasure::SquareFoot->value,
        32,
        1,
    );

    expect($preview['preview'])->toBe('1 Sheet = 32 Square foot')
        ->and($preview['converted_one'])->toBe('32.00000000')
        ->and($preview['derived_reciprocal'])->toContain('not stored')
        ->and($preview['derived_reciprocal'])->toContain('0.03125');
});

test('unit conversion preview rejects same unit and non positive ratios', function () {
    expect(fn () => UnitConversionPreview::make('sheet', 'sheet', 1, 1))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => UnitConversionPreview::make('sheet', 'sq_ft', 0, 1))
        ->toThrow(InvalidArgumentException::class);
});
