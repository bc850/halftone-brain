<?php

use App\Enums\QuoteAdjustmentMethod;
use App\Enums\QuoteAdjustmentType;
use App\Enums\QuoteLineDiscountMethod;
use App\Enums\QuoteLineType;
use App\Enums\QuoteTaxCalculationStatus;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Quotes\Snapshots\CustomerSafeQuoteProjection;
use App\Support\Quotes\Snapshots\QuoteLineSnapshotValidator;
use App\Support\Quotes\Totals\InvalidQuoteTotalsException;
use App\Support\Quotes\Totals\QuoteAdjustmentCalculationInput;
use App\Support\Quotes\Totals\QuoteLineCalculationInput;
use App\Support\Quotes\Totals\QuoteTotalsCalculator;

function quoteLineInput(array $overrides = []): QuoteLineCalculationInput
{
    $defaults = [
        'key' => 'line-1',
        'lineType' => QuoteLineType::Custom,
        'nameSnapshot' => 'Custom Item',
        'customerDescriptionSnapshot' => 'Customer visible',
        'internalDescriptionSnapshot' => 'Internal only',
        'productId' => null,
        'organizationProductId' => null,
        'skuSnapshot' => null,
        'itemKindSnapshot' => null,
        'quantityScaled' => ComponentCostEstimator::quantityToScaled('1'),
        'uomSnapshot' => 'each',
        'calculatedUnitPriceCents' => 1000,
        'finalUnitPriceCents' => 1000,
        'lineDiscountMethod' => QuoteLineDiscountMethod::None,
        'lineDiscountValue' => 0,
        'isTaxable' => true,
        'priceOverride' => false,
        'overrideReason' => null,
        'belowMinimum' => false,
        'approvalRequired' => false,
        'approvalReasons' => null,
        'materialCostMicroUnits' => 2500,
        'laborCostMicroUnits' => 1000,
        'overheadCostMicroUnits' => 0,
        'totalCostMicroUnits' => 3500,
        'pricingMethodSnapshot' => null,
        'markupBasisPointsSnapshot' => null,
        'marginBasisPointsSnapshot' => null,
        'pricingVersionSnapshot' => null,
        'componentsVersionSnapshot' => null,
        'componentCostBreakdown' => null,
        'pricingInputSnapshot' => null,
        'pricingResultSnapshot' => null,
        'currencyCode' => 'USD',
    ];

    $data = [...$defaults, ...$overrides];

    return new QuoteLineCalculationInput(...$data);
}

function quoteAdjustmentInput(array $overrides = []): QuoteAdjustmentCalculationInput
{
    $defaults = [
        'key' => 'adj-1',
        'adjustmentType' => QuoteAdjustmentType::Fee,
        'descriptionSnapshot' => 'Fee',
        'method' => QuoteAdjustmentMethod::Fixed,
        'inputValue' => 500,
        'isTaxable' => true,
        'approvalRequired' => false,
        'approvalReasons' => null,
    ];

    $data = [...$defaults, ...$overrides];

    return new QuoteAdjustmentCalculationInput(...$data);
}

test('line extension uses half-up boundaries without floats', function () {
    $calculator = new QuoteTotalsCalculator;

    $exact = $calculator->calculate([
        quoteLineInput([
            'quantityScaled' => ComponentCostEstimator::quantityToScaled('1.5'),
            'finalUnitPriceCents' => 100,
        ]),
    ]);
    expect($exact->lines[0]->extendedPriceCents)->toBe(150);

    $halfUp = $calculator->calculate([
        quoteLineInput([
            'key' => 'half',
            'quantityScaled' => ComponentCostEstimator::quantityToScaled('0.333333'),
            'finalUnitPriceCents' => 1,
        ]),
    ]);
    // 1 * 0.333333 = 0.333333 → rounds half-up to 0
    expect($halfUp->lines[0]->extendedPriceCents)->toBe(0);

    $roundUp = $calculator->calculate([
        quoteLineInput([
            'key' => 'up',
            'quantityScaled' => ComponentCostEstimator::quantityToScaled('0.5'),
            'finalUnitPriceCents' => 1,
        ]),
    ]);
    expect($roundUp->lines[0]->extendedPriceCents)->toBe(1);
});

test('fixed and percentage line discounts and caps', function () {
    $calculator = new QuoteTotalsCalculator;

    $fixed = $calculator->calculate([
        quoteLineInput([
            'finalUnitPriceCents' => 10000,
            'lineDiscountMethod' => QuoteLineDiscountMethod::Fixed,
            'lineDiscountValue' => 2500,
        ]),
    ]);
    expect($fixed->lines[0]->lineDiscountAmountCents)->toBe(2500)
        ->and($fixed->lines[0]->netLineTotalCents)->toBe(7500)
        ->and($fixed->lineDiscountTotalCents)->toBe(2500);

    $percent = $calculator->calculate([
        quoteLineInput([
            'finalUnitPriceCents' => 10000,
            'lineDiscountMethod' => QuoteLineDiscountMethod::Percentage,
            'lineDiscountValue' => 1250, // 12.50%
        ]),
    ]);
    expect($percent->lines[0]->lineDiscountAmountCents)->toBe(1250)
        ->and($percent->lines[0]->netLineTotalCents)->toBe(8750);

    expect(fn () => $calculator->calculate([
        quoteLineInput([
            'finalUnitPriceCents' => 1000,
            'lineDiscountMethod' => QuoteLineDiscountMethod::Fixed,
            'lineDiscountValue' => 1001,
        ]),
    ]))->toThrow(InvalidQuoteTotalsException::class);
});

test('quote discounts fees shipping installation and threshold', function () {
    $calculator = new QuoteTotalsCalculator;

    $result = $calculator->calculate(
        [
            quoteLineInput(['key' => 'a', 'finalUnitPriceCents' => 100_000]),
            quoteLineInput(['key' => 'b', 'finalUnitPriceCents' => 50_000]),
        ],
        [
            quoteAdjustmentInput([
                'key' => 'disc',
                'adjustmentType' => QuoteAdjustmentType::QuoteDiscount,
                'method' => QuoteAdjustmentMethod::Percentage,
                'inputValue' => 1000, // 10% of 150000 = 15000
                'isTaxable' => false,
            ]),
            quoteAdjustmentInput([
                'key' => 'ship',
                'adjustmentType' => QuoteAdjustmentType::Shipping,
                'inputValue' => 2500,
                'isTaxable' => true,
            ]),
            quoteAdjustmentInput([
                'key' => 'install',
                'adjustmentType' => QuoteAdjustmentType::Installation,
                'inputValue' => 7500,
                'isTaxable' => true,
            ]),
            quoteAdjustmentInput([
                'key' => 'fee',
                'adjustmentType' => QuoteAdjustmentType::Fee,
                'inputValue' => 1000,
                'isTaxable' => false,
            ]),
        ],
    );

    expect($result->netLineSubtotalCents)->toBe(150_000)
        ->and($result->quoteDiscountTotalCents)->toBe(15_000)
        ->and($result->positiveAdjustmentTotalsByType['shipping'])->toBe(2500)
        ->and($result->positiveAdjustmentTotalsByType['installation'])->toBe(7500)
        ->and($result->positiveAdjustmentTotalsByType['fee'])->toBe(1000)
        ->and($result->finalPretaxAmountCents)->toBe(146_000) // 150000 - 15000 + 2500 + 7500 + 1000
        ->and($result->approvalThresholdBasisCents)->toBe(146_000)
        ->and($result->meetsApprovalThreshold)->toBeFalse()
        ->and($result->taxUnresolved)->toBeTrue()
        ->and($result->taxStatus)->toBe(QuoteTaxCalculationStatus::Pending);
});

test('approval threshold requires final pretax strictly greater than $1500', function () {
    $calculator = new QuoteTotalsCalculator;
    $threshold = QuoteTotalsCalculator::APPROVAL_THRESHOLD_CENTS;

    $oneCentBelow = $calculator->calculate([
        quoteLineInput(['finalUnitPriceCents' => $threshold - 1]),
    ]);
    expect($oneCentBelow->finalPretaxAmountCents)->toBe(149_999)
        ->and($oneCentBelow->approvalThresholdBasisCents)->toBe(149_999)
        ->and($oneCentBelow->meetsApprovalThreshold)->toBeFalse();

    $exactlyThreshold = $calculator->calculate([
        quoteLineInput(['finalUnitPriceCents' => $threshold]),
    ]);
    expect($exactlyThreshold->finalPretaxAmountCents)->toBe(150_000)
        ->and($exactlyThreshold->approvalThresholdBasisCents)->toBe(150_000)
        ->and($exactlyThreshold->meetsApprovalThreshold)->toBeFalse();

    $oneCentAbove = $calculator->calculate([
        quoteLineInput(['finalUnitPriceCents' => $threshold + 1]),
    ]);
    expect($oneCentAbove->finalPretaxAmountCents)->toBe(150_001)
        ->and($oneCentAbove->approvalThresholdBasisCents)->toBe(150_001)
        ->and($oneCentAbove->meetsApprovalThreshold)->toBeTrue();
});

test('fees can push a quote from exactly $1500 to above the approval threshold', function () {
    $calculator = new QuoteTotalsCalculator;
    $threshold = QuoteTotalsCalculator::APPROVAL_THRESHOLD_CENTS;

    $atThreshold = $calculator->calculate([
        quoteLineInput(['finalUnitPriceCents' => $threshold]),
    ]);
    expect($atThreshold->finalPretaxAmountCents)->toBe(150_000)
        ->and($atThreshold->meetsApprovalThreshold)->toBeFalse();

    $withFee = $calculator->calculate(
        [quoteLineInput(['finalUnitPriceCents' => $threshold])],
        [quoteAdjustmentInput([
            'key' => 'fee-push',
            'adjustmentType' => QuoteAdjustmentType::Fee,
            'inputValue' => 1,
            'isTaxable' => false,
        ])],
    );
    expect($withFee->finalPretaxAmountCents)->toBe(150_001)
        ->and($withFee->positiveAdjustmentTotalsByType['fee'])->toBe(1)
        ->and($withFee->approvalThresholdBasisCents)->toBe(150_001)
        ->and($withFee->meetsApprovalThreshold)->toBeTrue();
});

test('quote discount can bring an above-threshold quote down to exactly $1500', function () {
    $calculator = new QuoteTotalsCalculator;
    $threshold = QuoteTotalsCalculator::APPROVAL_THRESHOLD_CENTS;

    $above = $calculator->calculate([
        quoteLineInput(['finalUnitPriceCents' => $threshold + 500]),
    ]);
    expect($above->finalPretaxAmountCents)->toBe(150_500)
        ->and($above->meetsApprovalThreshold)->toBeTrue();

    $discountedToExact = $calculator->calculate(
        [quoteLineInput(['finalUnitPriceCents' => $threshold + 500])],
        [quoteAdjustmentInput([
            'key' => 'discount-to-threshold',
            'adjustmentType' => QuoteAdjustmentType::QuoteDiscount,
            'method' => QuoteAdjustmentMethod::Fixed,
            'inputValue' => 500,
        ])],
    );
    expect($discountedToExact->finalPretaxAmountCents)->toBe(150_000)
        ->and($discountedToExact->quoteDiscountTotalCents)->toBe(500)
        ->and($discountedToExact->approvalThresholdBasisCents)->toBe(150_000)
        ->and($discountedToExact->meetsApprovalThreshold)->toBeFalse();
});

test('section and note lines contribute zero', function () {
    $calculator = new QuoteTotalsCalculator;

    $result = $calculator->calculate([
        quoteLineInput([
            'key' => 'section',
            'lineType' => QuoteLineType::Section,
            'quantityScaled' => 0,
            'finalUnitPriceCents' => null,
            'calculatedUnitPriceCents' => null,
            'materialCostMicroUnits' => null,
            'laborCostMicroUnits' => null,
            'overheadCostMicroUnits' => null,
            'totalCostMicroUnits' => null,
            'isTaxable' => false,
        ]),
        quoteLineInput([
            'key' => 'note',
            'lineType' => QuoteLineType::Note,
            'quantityScaled' => 0,
            'finalUnitPriceCents' => null,
            'calculatedUnitPriceCents' => null,
            'materialCostMicroUnits' => null,
            'laborCostMicroUnits' => null,
            'overheadCostMicroUnits' => null,
            'totalCostMicroUnits' => null,
            'isTaxable' => false,
        ]),
        quoteLineInput(['key' => 'money', 'finalUnitPriceCents' => 2000]),
    ]);

    expect($result->grossLineSubtotalCents)->toBe(2000)
        ->and($result->lines[0]->netLineTotalCents)->toBe(0)
        ->and($result->lines[1]->netLineTotalCents)->toBe(0);
});

test('quote discount cannot exceed eligible subtotal and ignores charges for base', function () {
    $calculator = new QuoteTotalsCalculator;

    expect(fn () => $calculator->calculate(
        [quoteLineInput(['finalUnitPriceCents' => 1000])],
        [quoteAdjustmentInput([
            'adjustmentType' => QuoteAdjustmentType::QuoteDiscount,
            'method' => QuoteAdjustmentMethod::Fixed,
            'inputValue' => 1001,
            'isTaxable' => false,
        ])],
    ))->toThrow(InvalidQuoteTotalsException::class);

    $result = $calculator->calculate(
        [quoteLineInput(['finalUnitPriceCents' => 10_000])],
        [
            quoteAdjustmentInput([
                'key' => 'disc',
                'adjustmentType' => QuoteAdjustmentType::QuoteDiscount,
                'method' => QuoteAdjustmentMethod::Fixed,
                'inputValue' => 1000,
                'isTaxable' => false,
            ]),
            quoteAdjustmentInput([
                'key' => 'ship',
                'adjustmentType' => QuoteAdjustmentType::Shipping,
                'inputValue' => 5000,
            ]),
        ],
    );

    // Discount applies to 10000 line subtotal, not including shipping.
    expect($result->quoteDiscountTotalCents)->toBe(1000)
        ->and($result->finalPretaxAmountCents)->toBe(14_000);
});

test('overflow is rejected', function () {
    $calculator = new QuoteTotalsCalculator;

    expect(fn () => $calculator->calculate([
        quoteLineInput([
            'key' => 'a',
            'finalUnitPriceCents' => PHP_INT_MAX,
            'quantityScaled' => ComponentCostEstimator::quantityToScaled('2'),
        ]),
    ]))->toThrow(InvalidQuoteTotalsException::class);
});

test('catalog and custom snapshot validation', function () {
    $validator = new QuoteLineSnapshotValidator;

    expect(fn () => $validator->validate(quoteLineInput([
        'lineType' => QuoteLineType::Catalog,
        'productId' => null,
        'organizationProductId' => 1,
        'skuSnapshot' => 'SKU',
        'pricingVersionSnapshot' => 1,
    ])))->toThrow(InvalidQuoteTotalsException::class);

    $validator->validate(quoteLineInput([
        'lineType' => QuoteLineType::Catalog,
        'productId' => 1,
        'organizationProductId' => 2,
        'skuSnapshot' => 'SKU-1',
        'pricingVersionSnapshot' => 3,
        'componentsVersionSnapshot' => 1,
    ]));

    expect(fn () => $validator->validate(quoteLineInput([
        'lineType' => QuoteLineType::Custom,
        'productId' => 9,
    ])))->toThrow(InvalidQuoteTotalsException::class);
});

test('customer-safe projection omits costs and approval internals', function () {
    $calculator = new QuoteTotalsCalculator;
    $line = quoteLineInput([
        'approvalRequired' => true,
        'approvalReasons' => ['below_minimum'],
        'overrideReason' => 'manager ok',
        'componentCostBreakdown' => ['rows' => [['cost' => 1]]],
        'markupBasisPointsSnapshot' => 5000,
    ]);
    $totals = $calculator->calculate([$line]);
    $projection = (new CustomerSafeQuoteProjection)->fromTotals($totals, [$line]);
    $encoded = json_encode($projection);

    foreach (CustomerSafeQuoteProjection::forbiddenKeys() as $key) {
        expect($encoded)->not->toContain($key);
    }

    expect($projection['tax_unresolved'])->toBeTrue()
        ->and($projection['customer_grand_total_final'])->toBeFalse()
        ->and($projection['lines'][0])->not->toHaveKey('internal_description')
        ->and($projection['lines'][0]['net_line_total_cents'])->toBe(1000);
});

test('quantity precision rejects more than six decimals', function () {
    expect(fn () => ComponentCostEstimator::quantityToScaled('1.1234567'))
        ->toThrow(InvalidArgumentException::class);
});
