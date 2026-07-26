<?php

use App\Enums\ItemKind;
use App\Enums\UnitOfMeasure;
use App\Support\Catalog\ComponentCost\ComponentConversionDirection;
use App\Support\Catalog\ComponentCost\ComponentConversionInput;
use App\Support\Catalog\ComponentCost\ComponentCostEstimateInput;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Catalog\ComponentCost\ComponentLineInput;
use App\Support\Catalog\ComponentCost\InvalidComponentCostException;
use App\Support\Money;

function estimator(): ComponentCostEstimator
{
    return new ComponentCostEstimator;
}

/**
 * @param  list<ComponentLineInput>  $components
 */
function estimateInput(array $components = [], array $overrides = []): ComponentCostEstimateInput
{
    $defaults = [
        'organizationProductId' => 100,
        'parentAccountId' => 1,
        'organizationId' => 10,
        'itemKind' => ItemKind::Product,
        'isSellable' => true,
        'components' => $components,
    ];

    $data = [...$defaults, ...$overrides];

    return new ComponentCostEstimateInput(...$data);
}

/**
 * @param  list<ComponentConversionInput>  $conversions
 */
function acmComponent(array $overrides = [], array $conversions = []): ComponentLineInput
{
    $defaults = [
        'componentOrganizationProductId' => 200,
        'parentAccountId' => 1,
        'organizationId' => 10,
        'itemKind' => ItemKind::Material,
        'isPurchasable' => true,
        'purchaseUnitOfMeasure' => UnitOfMeasure::Sheet,
        'purchaseCostMicroUnits' => Money::dollarsToMicroUnits('80'),
        'quantityScaled' => ComponentCostEstimator::quantityToScaled('10'),
        'usageUnitOfMeasure' => UnitOfMeasure::SquareFoot,
        'wasteBasisPoints' => 1000,
        'conversions' => $conversions !== [] ? $conversions : [
            new ComponentConversionInput(
                UnitOfMeasure::Sheet,
                UnitOfMeasure::SquareFoot,
                32,
                1,
                true,
            ),
        ],
    ];

    $data = [...$defaults, ...$overrides];

    return new ComponentLineInput(...$data);
}

test('money scale uses ten thousand micro-units per dollar', function () {
    expect(Money::MICRO_UNITS_PER_DOLLAR)->toBe(10_000)
        ->and(Money::MICRO_UNITS_PER_CENT)->toBe(100)
        ->and(Money::COST_SCALE)->toBe(4)
        ->and(Money::dollarsToMicroUnits('27.50'))->toBe(275_000)
        ->and(Money::dollarsToMicroUnits('27.50'))->not->toBe(27_500_000)
        ->and(Money::microUnitsToDollars(275_000))->toBe('27.5000');
});

test('acm example estimates exactly twenty seven dollars fifty cents', function () {
    $result = estimator()->estimate(estimateInput([acmComponent()]));

    expect($result->totalEstimatedMaterialCostMicroUnits)
        ->toBe(Money::dollarsToMicroUnits('27.50'))
        ->and($result->isEstimateOnly)->toBeTrue()
        ->and($result->doesNotConsumeInventory)->toBeTrue()
        ->and($result->breakdowns)->toHaveCount(1)
        ->and($result->breakdowns[0]->wasteAdjustedQuantityScaled)
        ->toBe(ComponentCostEstimator::quantityToScaled('11'))
        ->and($result->breakdowns[0]->conversionDirection)
        ->toBe(ComponentConversionDirection::Reciprocal)
        ->and($result->breakdowns[0]->convertedPurchaseQuantity)->toBe('0.34375');
});

test('quantity scale is six decimals', function () {
    expect(ComponentCostEstimator::QUANTITY_SCALE)->toBe(6)
        ->and(ComponentCostEstimator::quantityToScaled('10'))->toBe(10_000_000)
        ->and(ComponentCostEstimator::quantityToScaled('10.123456'))->toBe(10_123_456)
        ->and(ComponentCostEstimator::scaledToQuantity(10_000_000))->toBe('10');
});

test('zero quantity is rejected', function () {
    expect(fn () => estimator()->estimate(estimateInput([
        acmComponent(['quantityScaled' => 0]),
    ])))->toThrow(InvalidComponentCostException::class, 'greater than zero');
});

test('waste zero fractional and one hundred percent are accepted', function () {
    $zero = estimator()->estimate(estimateInput([
        acmComponent(['wasteBasisPoints' => 0]),
    ]));
    expect($zero->totalEstimatedMaterialCostMicroUnits)
        ->toBe(Money::dollarsToMicroUnits('25'));

    $fractional = estimator()->estimate(estimateInput([
        acmComponent(['wasteBasisPoints' => 1]),
    ]));
    expect($fractional->breakdowns[0]->wasteAdjustedQuantityScaled)
        ->toBe(ComponentCostEstimator::quantityToScaled('10.001'));

    $full = estimator()->estimate(estimateInput([
        acmComponent(['wasteBasisPoints' => 10_000]),
    ]));
    expect($full->totalEstimatedMaterialCostMicroUnits)
        ->toBe(Money::dollarsToMicroUnits('50'))
        ->and($full->breakdowns[0]->wasteAdjustedQuantityScaled)
        ->toBe(ComponentCostEstimator::quantityToScaled('20'));
});

test('waste over one hundred percent is rejected', function () {
    expect(fn () => estimator()->estimate(estimateInput([
        acmComponent(['wasteBasisPoints' => 10_001]),
    ])))->toThrow(InvalidComponentCostException::class, 'Waste basis points');
});

test('identical uom requires no conversion row', function () {
    $result = estimator()->estimate(estimateInput([
        acmComponent([
            'usageUnitOfMeasure' => UnitOfMeasure::Sheet,
            'quantityScaled' => ComponentCostEstimator::quantityToScaled('1'),
            'wasteBasisPoints' => 0,
            'conversions' => [],
        ]),
    ]));

    expect($result->totalEstimatedMaterialCostMicroUnits)
        ->toBe(Money::dollarsToMicroUnits('80'))
        ->and($result->breakdowns[0]->conversionDirection)
        ->toBe(ComponentConversionDirection::Identical);
});

test('direct conversion from usage to purchase is used', function () {
    $result = estimator()->estimate(estimateInput([
        acmComponent([
            'conversions' => [
                new ComponentConversionInput(
                    UnitOfMeasure::SquareFoot,
                    UnitOfMeasure::Sheet,
                    1,
                    32,
                    true,
                ),
            ],
        ]),
    ]));

    expect($result->totalEstimatedMaterialCostMicroUnits)
        ->toBe(Money::dollarsToMicroUnits('27.50'))
        ->and($result->breakdowns[0]->conversionDirection)
        ->toBe(ComponentConversionDirection::Direct);
});

test('reciprocal conversion is used when direct is absent', function () {
    $result = estimator()->estimate(estimateInput([acmComponent()]));

    expect($result->breakdowns[0]->conversionDirection)
        ->toBe(ComponentConversionDirection::Reciprocal);
});

test('conflicting direct and reciprocal conversions fail', function () {
    expect(fn () => estimator()->estimate(estimateInput([
        acmComponent([
            'conversions' => [
                new ComponentConversionInput(
                    UnitOfMeasure::SquareFoot,
                    UnitOfMeasure::Sheet,
                    1,
                    32,
                    true,
                ),
                new ComponentConversionInput(
                    UnitOfMeasure::Sheet,
                    UnitOfMeasure::SquareFoot,
                    30,
                    1,
                    true,
                ),
            ],
        ]),
    ])))->toThrow(InvalidComponentCostException::class, 'disagree');
});

test('agreeing direct and reciprocal conversions succeed via direct', function () {
    $result = estimator()->estimate(estimateInput([
        acmComponent([
            'conversions' => [
                new ComponentConversionInput(
                    UnitOfMeasure::SquareFoot,
                    UnitOfMeasure::Sheet,
                    1,
                    32,
                    true,
                ),
                new ComponentConversionInput(
                    UnitOfMeasure::Sheet,
                    UnitOfMeasure::SquareFoot,
                    32,
                    1,
                    true,
                ),
            ],
        ]),
    ]));

    expect($result->totalEstimatedMaterialCostMicroUnits)
        ->toBe(Money::dollarsToMicroUnits('27.50'))
        ->and($result->breakdowns[0]->conversionDirection)
        ->toBe(ComponentConversionDirection::Direct);
});

test('missing conversion is rejected', function () {
    expect(fn () => estimator()->estimate(estimateInput([
        acmComponent(['conversions' => []]),
    ])))->toThrow(InvalidComponentCostException::class, 'missing');
});

test('inactive required conversion is rejected', function () {
    expect(fn () => estimator()->estimate(estimateInput([
        acmComponent([
            'conversions' => [
                new ComponentConversionInput(
                    UnitOfMeasure::Sheet,
                    UnitOfMeasure::SquareFoot,
                    32,
                    1,
                    false,
                ),
            ],
        ]),
    ])))->toThrow(InvalidComponentCostException::class, 'inactive');
});

test('null purchase cost and uom are rejected', function () {
    expect(fn () => estimator()->estimate(estimateInput([
        acmComponent(['purchaseCostMicroUnits' => null]),
    ])))->toThrow(InvalidComponentCostException::class, 'purchase cost');

    expect(fn () => estimator()->estimate(estimateInput([
        acmComponent(['purchaseUnitOfMeasure' => null]),
    ])))->toThrow(InvalidComponentCostException::class, 'purchase unit');
});

test('parent and component eligibility rules are enforced', function () {
    expect(fn () => estimator()->estimate(estimateInput(
        [acmComponent()],
        ['itemKind' => ItemKind::Material],
    )))->toThrow(InvalidComponentCostException::class, 'product or service');

    expect(fn () => estimator()->estimate(estimateInput(
        [acmComponent()],
        ['isSellable' => false],
    )))->toThrow(InvalidComponentCostException::class, 'sellable');

    expect(fn () => estimator()->estimate(estimateInput([
        acmComponent(['itemKind' => ItemKind::Product]),
    ])))->toThrow(InvalidComponentCostException::class, 'material');

    expect(fn () => estimator()->estimate(estimateInput([
        acmComponent(['isPurchasable' => false]),
    ])))->toThrow(InvalidComponentCostException::class, 'purchasable');

    expect(estimator()->estimate(estimateInput(
        [acmComponent()],
        ['itemKind' => ItemKind::Service],
    ))->totalEstimatedMaterialCostMicroUnits)->toBe(Money::dollarsToMicroUnits('27.50'));
});

test('cross organization and self reference are rejected', function () {
    expect(fn () => estimator()->estimate(estimateInput([
        acmComponent(['organizationId' => 99]),
    ])))->toThrow(InvalidComponentCostException::class, 'same organization');

    expect(fn () => estimator()->estimate(estimateInput([
        acmComponent(['componentOrganizationProductId' => 100]),
    ])))->toThrow(InvalidComponentCostException::class, 'itself');
});

test('multiple components sum with overflow protection', function () {
    $second = acmComponent([
        'componentOrganizationProductId' => 201,
        'quantityScaled' => ComponentCostEstimator::quantityToScaled('5'),
        'wasteBasisPoints' => 0,
    ]);

    $result = estimator()->estimate(estimateInput([acmComponent(), $second]));

    // 27.50 + (5/32 * 80) = 27.50 + 12.50 = 40.00
    expect($result->totalEstimatedMaterialCostMicroUnits)
        ->toBe(Money::dollarsToMicroUnits('40'))
        ->and($result->breakdowns)->toHaveCount(2);
});

test('half-up boundary rounds component cost once', function () {
    // 1 sq ft, 0% waste, $80/sheet, 32 sq ft/sheet → 80/32 = 2.5 exactly
    $exact = estimator()->estimate(estimateInput([
        acmComponent([
            'quantityScaled' => ComponentCostEstimator::quantityToScaled('1'),
            'wasteBasisPoints' => 0,
        ]),
    ]));
    expect($exact->totalEstimatedMaterialCostMicroUnits)
        ->toBe(Money::dollarsToMicroUnits('2.50'));

    // Force a half-up: purchase cost 1 micro-unit, convert 1.5 purchase units → 2
    $halfUp = estimator()->estimate(estimateInput([
        acmComponent([
            'purchaseCostMicroUnits' => 1,
            'purchaseUnitOfMeasure' => UnitOfMeasure::Each,
            'usageUnitOfMeasure' => UnitOfMeasure::Each,
            'quantityScaled' => ComponentCostEstimator::quantityToScaled('1.5'),
            'wasteBasisPoints' => 0,
            'conversions' => [],
        ]),
    ]));
    expect($halfUp->totalEstimatedMaterialCostMicroUnits)->toBe(2);
});

test('overflow on micro-unit sum is rejected', function () {
    expect(fn () => estimator()->estimate(estimateInput([
        acmComponent([
            'purchaseCostMicroUnits' => PHP_INT_MAX,
            'purchaseUnitOfMeasure' => UnitOfMeasure::Each,
            'usageUnitOfMeasure' => UnitOfMeasure::Each,
            'quantityScaled' => ComponentCostEstimator::quantityToScaled('1'),
            'wasteBasisPoints' => 0,
            'conversions' => [],
        ]),
        acmComponent([
            'componentOrganizationProductId' => 201,
            'purchaseCostMicroUnits' => 1,
            'purchaseUnitOfMeasure' => UnitOfMeasure::Each,
            'usageUnitOfMeasure' => UnitOfMeasure::Each,
            'quantityScaled' => ComponentCostEstimator::quantityToScaled('1'),
            'wasteBasisPoints' => 0,
            'conversions' => [],
        ]),
    ])))->toThrow(InvalidArgumentException::class);
});

test('pure estimator class has no persistence dependencies', function () {
    $source = file_get_contents(
        (new ReflectionClass(ComponentCostEstimator::class))->getFileName()
    );

    expect($source)->not->toContain('use Illuminate\\Support\\Facades\\DB')
        ->and($source)->not->toContain('use Illuminate\\Database\\')
        ->and($source)->not->toContain('use App\\Models\\')
        ->and($source)->not->toContain('TenantContext')
        ->and($source)->not->toContain('AuditEvent');
});
