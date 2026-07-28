<?php

use App\Support\Money;

test('dollars convert to cents with half-up rounding', function () {
    expect(Money::dollarsToCents('10.004'))->toBe(1000)
        ->and(Money::dollarsToCents('10.005'))->toBe(1001)
        ->and(Money::centsToDollars(15000))->toBe('150.00');
});

test('micro-units preserve four decimal cost precision', function () {
    expect(Money::dollarsToMicroUnits('100.1234'))->toBe(1_001_234)
        ->and(Money::microUnitsToDollars(1_001_234))->toBe('100.1234');
});

test('markup basis points convert to and from percent strings', function () {
    expect(Money::percentToBasisPoints('50'))->toBe(5000)
        ->and(Money::percentToBasisPoints('50.25'))->toBe(5025)
        ->and(Money::basisPointsToPercent(5025))->toBe('50.25');
});

test('tax percentages convert to and from parts per million', function () {
    expect(Money::percentToRatePartsPerMillion('8'))->toBe(80_000)
        ->and(Money::percentToRatePartsPerMillion('8.5'))->toBe(85_000)
        ->and(Money::percentToRatePartsPerMillion('7.375'))->toBe(73_750)
        ->and(Money::percentToRatePartsPerMillion('0'))->toBe(0)
        ->and(Money::percentToRatePartsPerMillion('100'))->toBe(1_000_000)
        ->and(Money::ratePartsPerMillionToPercent(85_000))->toBe('8.5000');
});

test('tax percentage conversion rounds half up and rejects invalid input', function () {
    expect(Money::percentToRatePartsPerMillion('0.00005'))->toBe(1)
        ->and(Money::percentToRatePartsPerMillion('0.000049'))->toBe(0)
        ->and(fn () => Money::percentToRatePartsPerMillion('-1'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::ratePartsPerMillionToPercent(-1))->toThrow(InvalidArgumentException::class);
});

test('suggested list price uses integer math from cost and markup', function () {
    $costMicroUnits = Money::dollarsToMicroUnits('100');
    $markupBasisPoints = Money::percentToBasisPoints('50');

    expect(Money::suggestedListPriceCents($costMicroUnits, $markupBasisPoints))->toBe(15000);
});
