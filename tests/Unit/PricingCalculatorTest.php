<?php

use App\Enums\OverheadMode;
use App\Enums\PricingMethod;
use App\Support\Money;
use App\Support\Pricing\InvalidPricingException;
use App\Support\Pricing\PricingCalculator;
use App\Support\Pricing\PricingInput;

function pricingInput(array $overrides = []): PricingInput
{
    $defaults = [
        'materialCostMicroUnits' => 0,
        'laborCostMicroUnits' => 0,
        'overheadMode' => OverheadMode::None,
        'overheadAmountMicroUnits' => 0,
        'overheadRateBasisPoints' => 0,
        'pricingMethod' => PricingMethod::Markup,
        'markupBasisPoints' => 0,
        'targetMarginBasisPoints' => 0,
        'fixedPriceCents' => null,
        'minimumPriceCents' => null,
        'allowPriceOverride' => false,
        'requestedOverridePriceCents' => null,
        'quantity' => '1',
        'currencyCode' => PricingCalculator::CURRENCY_USD,
        'pricingVersion' => 1,
    ];

    $data = [...$defaults, ...$overrides];

    return new PricingInput(...$data);
}

function calculator(): PricingCalculator
{
    return new PricingCalculator;
}

test('approved example: material labor fixed overhead with fifty percent markup', function () {
    $result = calculator()->calculate(pricingInput([
        'materialCostMicroUnits' => Money::dollarsToMicroUnits('40'),
        'laborCostMicroUnits' => Money::dollarsToMicroUnits('30'),
        'overheadMode' => OverheadMode::Fixed,
        'overheadAmountMicroUnits' => Money::dollarsToMicroUnits('10'),
        'pricingMethod' => PricingMethod::Markup,
        'markupBasisPoints' => Money::percentToBasisPoints('50'),
    ]));

    expect($result->totalUnitCostMicroUnits)->toBe(Money::dollarsToMicroUnits('80'))
        ->and($result->calculatedUnitPriceCents)->toBe(12000)
        ->and($result->finalUnitPriceCents)->toBe(12000)
        ->and($result->extendedPriceCents)->toBe(12000)
        ->and($result->belowMinimum)->toBeFalse()
        ->and($result->approvalRequired)->toBeFalse();
});

test('approved example: eighty dollar cost with forty percent target margin', function () {
    $result = calculator()->calculate(pricingInput([
        'materialCostMicroUnits' => Money::dollarsToMicroUnits('80'),
        'pricingMethod' => PricingMethod::TargetMargin,
        'targetMarginBasisPoints' => Money::percentToBasisPoints('40'),
    ]));

    expect($result->calculatedUnitPriceCents)->toBe(13333)
        ->and($result->finalUnitPriceCents)->toBe(13333);
});

test('approved example: fixed price ninety nine dollars', function () {
    $result = calculator()->calculate(pricingInput([
        'materialCostMicroUnits' => Money::dollarsToMicroUnits('80'),
        'pricingMethod' => PricingMethod::Fixed,
        'fixedPriceCents' => 9900,
    ]));

    expect($result->calculatedUnitPriceCents)->toBe(9900)
        ->and($result->finalUnitPriceCents)->toBe(9900);
});

test('percentage overhead applies to material plus labor', function () {
    $result = calculator()->calculate(pricingInput([
        'materialCostMicroUnits' => Money::dollarsToMicroUnits('40'),
        'laborCostMicroUnits' => Money::dollarsToMicroUnits('30'),
        'overheadMode' => OverheadMode::Rate,
        'overheadRateBasisPoints' => Money::percentToBasisPoints('10'),
        'pricingMethod' => PricingMethod::Fixed,
        'fixedPriceCents' => 10000,
    ]));

    // 10% of $70 = $7.00 = 70000 micro-units
    expect($result->overheadCostMicroUnits)->toBe(Money::dollarsToMicroUnits('7'))
        ->and($result->totalUnitCostMicroUnits)->toBe(Money::dollarsToMicroUnits('77'));
});

test('half-up rounding around cent boundaries for markup', function () {
    // 0.004 dollars = 40 micro-units → 0 cents; 0.005 = 50 micro → 1 cent
    expect(Money::microUnitsToCents(49))->toBe(0)
        ->and(Money::microUnitsToCents(50))->toBe(1)
        ->and(Money::microUnitsToCents(149))->toBe(1)
        ->and(Money::microUnitsToCents(150))->toBe(2);

    // Cost that produces a sell amount just below/above half-cent after markup
    $below = calculator()->calculate(pricingInput([
        'materialCostMicroUnits' => 1, // $0.0001
        'markupBasisPoints' => 0,
    ]));
    expect($below->calculatedUnitPriceCents)->toBe(0);

    $above = calculator()->calculate(pricingInput([
        'materialCostMicroUnits' => 50, // $0.0050
        'markupBasisPoints' => 0,
    ]));
    expect($above->calculatedUnitPriceCents)->toBe(1);
});

test('repeating target-margin division rounds half-up to cents', function () {
    $result = calculator()->calculate(pricingInput([
        'materialCostMicroUnits' => Money::dollarsToMicroUnits('80'),
        'pricingMethod' => PricingMethod::TargetMargin,
        'targetMarginBasisPoints' => Money::percentToBasisPoints('40'),
    ]));

    expect($result->calculatedUnitPriceCents)->toBe(13333);
});

test('fractional quantities and extended total rounding', function () {
    $result = calculator()->calculate(pricingInput([
        'pricingMethod' => PricingMethod::Fixed,
        'fixedPriceCents' => 100, // $1.00
        'quantity' => '2.5',
    ]));

    expect($result->quantity)->toBe('2.5')
        ->and($result->extendedPriceCents)->toBe(250);

    $rounded = calculator()->calculate(pricingInput([
        'pricingMethod' => PricingMethod::Fixed,
        'fixedPriceCents' => 333, // $3.33
        'quantity' => '10.125',
    ]));

    // 333 * 10.125 = 3371.625 → half-up 3372
    expect($rounded->extendedPriceCents)->toBe(3372);
});

test('zero cost products calculate zero sell under markup', function () {
    $result = calculator()->calculate(pricingInput([
        'markupBasisPoints' => Money::percentToBasisPoints('50'),
    ]));

    expect($result->totalUnitCostMicroUnits)->toBe(0)
        ->and($result->calculatedUnitPriceCents)->toBe(0)
        ->and($result->extendedPriceCents)->toBe(0);
});

test('very large valid values succeed while overflow is rejected', function () {
    $large = calculator()->calculate(pricingInput([
        'materialCostMicroUnits' => 1_000_000_000_000, // $100,000,000.00
        'markupBasisPoints' => Money::percentToBasisPoints('10'),
    ]));

    expect($large->calculatedUnitPriceCents)->toBe(11_000_000_000);

    expect(fn () => Money::addMicroUnits(PHP_INT_MAX, 1))
        ->toThrow(InvalidArgumentException::class);
});

test('rejects inconsistent overhead configurations', function (array $overrides, string $message) {
    expect(fn () => calculator()->calculate(pricingInput($overrides)))
        ->toThrow(InvalidPricingException::class, $message);
})->with([
    'none with amount' => [[
        'overheadMode' => OverheadMode::None,
        'overheadAmountMicroUnits' => 1,
    ], 'When overhead mode is none, overhead amount and rate must be zero.'],
    'none with rate' => [[
        'overheadMode' => OverheadMode::None,
        'overheadRateBasisPoints' => 1,
    ], 'When overhead mode is none, overhead amount and rate must be zero.'],
    'fixed with rate' => [[
        'overheadMode' => OverheadMode::Fixed,
        'overheadAmountMicroUnits' => 100,
        'overheadRateBasisPoints' => 1,
    ], 'When overhead mode is fixed, overhead rate must be zero.'],
    'rate with amount' => [[
        'overheadMode' => OverheadMode::Rate,
        'overheadAmountMicroUnits' => 1,
        'overheadRateBasisPoints' => 100,
    ], 'When overhead mode is rate, fixed overhead amount must be zero.'],
]);

test('rejects inconsistent pricing method configurations', function (array $overrides, string $message) {
    expect(fn () => calculator()->calculate(pricingInput($overrides)))
        ->toThrow(InvalidPricingException::class, $message);
})->with([
    'markup with target margin' => [[
        'markupBasisPoints' => 100,
        'targetMarginBasisPoints' => 1,
    ], 'When pricing method is markup, target margin must be zero.'],
    'markup with fixed price' => [[
        'fixedPriceCents' => 100,
    ], 'When pricing method is markup, fixed price must be null.'],
    'target margin with markup' => [[
        'pricingMethod' => PricingMethod::TargetMargin,
        'markupBasisPoints' => 1,
        'targetMarginBasisPoints' => 1000,
    ], 'When pricing method is target margin, markup must be zero.'],
    'target margin with fixed' => [[
        'pricingMethod' => PricingMethod::TargetMargin,
        'targetMarginBasisPoints' => 1000,
        'fixedPriceCents' => 100,
    ], 'When pricing method is target margin, fixed price must be null.'],
    'target margin at 100 percent' => [[
        'pricingMethod' => PricingMethod::TargetMargin,
        'targetMarginBasisPoints' => 10_000,
    ], 'Target margin must be below 100%.'],
    'fixed without price' => [[
        'pricingMethod' => PricingMethod::Fixed,
    ], 'When pricing method is fixed, fixed price is required.'],
    'fixed with markup' => [[
        'pricingMethod' => PricingMethod::Fixed,
        'fixedPriceCents' => 100,
        'markupBasisPoints' => 1,
    ], 'When pricing method is fixed, markup and target margin must be zero.'],
]);

test('rejects negative inputs unsupported currency malformed quantity and invalid version', function (array $overrides, string $message) {
    expect(fn () => calculator()->calculate(pricingInput($overrides)))
        ->toThrow(InvalidPricingException::class, $message);
})->with([
    'negative material' => [['materialCostMicroUnits' => -1], 'material cost cannot be negative.'],
    'unsupported currency' => [['currencyCode' => 'EUR'], 'Only USD currency is supported.'],
    'pricing version zero' => [['pricingVersion' => 0], 'Pricing version must be at least 1.'],
    'zero quantity' => [['quantity' => '0'], 'Quantity must be greater than zero.'],
    'negative quantity string' => [['quantity' => '-1'], 'Invalid quantity decimal string.'],
    'exponent quantity' => [['quantity' => '1e2'], 'Quantity may not use exponent notation.'],
    'excessive precision' => [['quantity' => '1.1234567'], 'Quantity may have at most 6 decimal places.'],
]);

test('minimum and override behavior', function () {
    $above = calculator()->calculate(pricingInput([
        'pricingMethod' => PricingMethod::Fixed,
        'fixedPriceCents' => 12000,
        'minimumPriceCents' => 10000,
    ]));
    expect($above->belowMinimum)->toBeFalse()->and($above->approvalRequired)->toBeFalse();

    $exact = calculator()->calculate(pricingInput([
        'pricingMethod' => PricingMethod::Fixed,
        'fixedPriceCents' => 10000,
        'minimumPriceCents' => 10000,
    ]));
    expect($exact->belowMinimum)->toBeFalse();

    $below = calculator()->calculate(pricingInput([
        'pricingMethod' => PricingMethod::Fixed,
        'fixedPriceCents' => 9000,
        'minimumPriceCents' => 10000,
    ]));
    expect($below->belowMinimum)->toBeTrue()
        ->and($below->approvalRequired)->toBeTrue()
        ->and($below->finalUnitPriceCents)->toBe(9000)
        ->and($below->warnings)->toContain('Final unit price is below the configured minimum price.');

    $allowedAbove = calculator()->calculate(pricingInput([
        'pricingMethod' => PricingMethod::Fixed,
        'fixedPriceCents' => 12000,
        'minimumPriceCents' => 10000,
        'allowPriceOverride' => true,
        'requestedOverridePriceCents' => 15000,
    ]));
    expect($allowedAbove->overrideApplied)->toBeTrue()
        ->and($allowedAbove->calculatedUnitPriceCents)->toBe(12000)
        ->and($allowedAbove->finalUnitPriceCents)->toBe(15000)
        ->and($allowedAbove->belowMinimum)->toBeFalse();

    $allowedBelow = calculator()->calculate(pricingInput([
        'pricingMethod' => PricingMethod::Fixed,
        'fixedPriceCents' => 12000,
        'minimumPriceCents' => 10000,
        'allowPriceOverride' => true,
        'requestedOverridePriceCents' => 8000,
    ]));
    expect($allowedBelow->overrideApplied)->toBeTrue()
        ->and($allowedBelow->calculatedUnitPriceCents)->toBe(12000)
        ->and($allowedBelow->finalUnitPriceCents)->toBe(8000)
        ->and($allowedBelow->belowMinimum)->toBeTrue()
        ->and($allowedBelow->approvalRequired)->toBeTrue();

    expect(fn () => calculator()->calculate(pricingInput([
        'pricingMethod' => PricingMethod::Fixed,
        'fixedPriceCents' => 12000,
        'allowPriceOverride' => false,
        'requestedOverridePriceCents' => 8000,
    ])))->toThrow(InvalidPricingException::class, 'Price override is not allowed for this organization product.');
});

test('overhead rate rounds half-up to micro-units', function () {
    // base 3 micro-units, 50% → 1.5 → 2 micro-units
    $result = calculator()->calculate(pricingInput([
        'materialCostMicroUnits' => 3,
        'overheadMode' => OverheadMode::Rate,
        'overheadRateBasisPoints' => 5000,
        'pricingMethod' => PricingMethod::Fixed,
        'fixedPriceCents' => 1,
    ]));

    expect($result->overheadCostMicroUnits)->toBe(2);
});
