<?php

use App\Enums\UnitOfMeasure;
use App\Support\Catalog\ComponentCost\ComponentConversionInput;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Catalog\ComponentCost\InvalidComponentCostException;
use App\Support\Catalog\VendorPackagePriceNormalizer;
use App\Support\Money;

test('normalizes identical uom package price to effective unit cost', function () {
    $normalizer = new VendorPackagePriceNormalizer;

    // $800 package / 10 sheets = $80/sheet
    $effective = $normalizer->normalize(
        packagePriceMicroUnits: Money::dollarsToMicroUnits('800'),
        packageQuantityScaled: ComponentCostEstimator::quantityToScaled('10'),
        offeringPurchaseUom: UnitOfMeasure::Sheet,
        organizationPurchaseUom: UnitOfMeasure::Sheet,
        conversions: [],
    );

    expect($effective)->toBe(Money::dollarsToMicroUnits('80'));
});

test('normalizes with direct sheet to square foot conversion', function () {
    $normalizer = new VendorPackagePriceNormalizer;

    // $80/sheet, 1 sheet = 32 sqft → $2.50/sqft
    $effective = $normalizer->normalize(
        packagePriceMicroUnits: Money::dollarsToMicroUnits('80'),
        packageQuantityScaled: ComponentCostEstimator::quantityToScaled('1'),
        offeringPurchaseUom: UnitOfMeasure::Sheet,
        organizationPurchaseUom: UnitOfMeasure::SquareFoot,
        conversions: [
            new ComponentConversionInput(
                fromUnit: UnitOfMeasure::Sheet,
                toUnit: UnitOfMeasure::SquareFoot,
                numerator: 32,
                denominator: 1,
                isActive: true,
            ),
        ],
    );

    expect($effective)->toBe(Money::dollarsToMicroUnits('2.50'));
});

test('normalizes with reciprocal conversion only', function () {
    $normalizer = new VendorPackagePriceNormalizer;

    $effective = $normalizer->normalize(
        packagePriceMicroUnits: Money::dollarsToMicroUnits('80'),
        packageQuantityScaled: ComponentCostEstimator::quantityToScaled('1'),
        offeringPurchaseUom: UnitOfMeasure::Sheet,
        organizationPurchaseUom: UnitOfMeasure::SquareFoot,
        conversions: [
            new ComponentConversionInput(
                fromUnit: UnitOfMeasure::SquareFoot,
                toUnit: UnitOfMeasure::Sheet,
                numerator: 1,
                denominator: 32,
                isActive: true,
            ),
        ],
    );

    expect($effective)->toBe(Money::dollarsToMicroUnits('2.50'));
});

test('produces fractional effective costs with half-up rounding', function () {
    $normalizer = new VendorPackagePriceNormalizer;

    // $100 / 3 sheets = 33.3333... → $33.3333 micro half-up
    $effective = $normalizer->normalize(
        packagePriceMicroUnits: Money::dollarsToMicroUnits('100'),
        packageQuantityScaled: ComponentCostEstimator::quantityToScaled('3'),
        offeringPurchaseUom: UnitOfMeasure::Sheet,
        organizationPurchaseUom: UnitOfMeasure::Sheet,
        conversions: [],
    );

    expect($effective)->toBe(333333);
});

test('rejects missing conversion between different units', function () {
    $normalizer = new VendorPackagePriceNormalizer;

    expect(fn () => $normalizer->normalize(
        packagePriceMicroUnits: Money::dollarsToMicroUnits('80'),
        packageQuantityScaled: ComponentCostEstimator::quantityToScaled('1'),
        offeringPurchaseUom: UnitOfMeasure::Sheet,
        organizationPurchaseUom: UnitOfMeasure::SquareFoot,
        conversions: [],
    ))->toThrow(InvalidComponentCostException::class);
});

test('rejects conflicting direct and reciprocal conversions', function () {
    $normalizer = new VendorPackagePriceNormalizer;

    expect(fn () => $normalizer->normalize(
        packagePriceMicroUnits: Money::dollarsToMicroUnits('80'),
        packageQuantityScaled: ComponentCostEstimator::quantityToScaled('1'),
        offeringPurchaseUom: UnitOfMeasure::Sheet,
        organizationPurchaseUom: UnitOfMeasure::SquareFoot,
        conversions: [
            new ComponentConversionInput(
                fromUnit: UnitOfMeasure::Sheet,
                toUnit: UnitOfMeasure::SquareFoot,
                numerator: 32,
                denominator: 1,
                isActive: true,
            ),
            new ComponentConversionInput(
                fromUnit: UnitOfMeasure::SquareFoot,
                toUnit: UnitOfMeasure::Sheet,
                numerator: 1,
                denominator: 30,
                isActive: true,
            ),
        ],
    ))->toThrow(InvalidComponentCostException::class);
});

test('ignores inactive conversions', function () {
    $normalizer = new VendorPackagePriceNormalizer;

    expect(fn () => $normalizer->normalize(
        packagePriceMicroUnits: Money::dollarsToMicroUnits('80'),
        packageQuantityScaled: ComponentCostEstimator::quantityToScaled('1'),
        offeringPurchaseUom: UnitOfMeasure::Sheet,
        organizationPurchaseUom: UnitOfMeasure::SquareFoot,
        conversions: [
            new ComponentConversionInput(
                fromUnit: UnitOfMeasure::Sheet,
                toUnit: UnitOfMeasure::SquareFoot,
                numerator: 32,
                denominator: 1,
                isActive: false,
            ),
        ],
    ))->toThrow(InvalidComponentCostException::class);
});
