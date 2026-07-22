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

test('suggested list price uses integer math from cost and markup', function () {
    $costMicroUnits = Money::dollarsToMicroUnits('100');
    $markupBasisPoints = Money::percentToBasisPoints('50');

    expect(Money::suggestedListPriceCents($costMicroUnits, $markupBasisPoints))->toBe(15000);
});
