<?php

use App\Enums\QuoteLineDiscountMethod;
use App\Enums\QuoteLineType;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Quotes\Documents\CustomerQuoteDocumentIntegrity;
use App\Support\Quotes\Snapshots\CustomerSafeQuoteProjection;
use App\Support\Quotes\Totals\QuoteLineCalculationInput;
use App\Support\Quotes\Totals\QuoteTotalsCalculator;

test('customer quote document integrity builds canonical payloads without forbidden keys', function () {
    $integrity = new CustomerQuoteDocumentIntegrity;
    $line = new QuoteLineCalculationInput(
        key: 'line-1',
        lineType: QuoteLineType::Custom,
        nameSnapshot: 'Banner',
        customerDescriptionSnapshot: 'Customer visible',
        internalDescriptionSnapshot: 'Internal only',
        productId: null,
        organizationProductId: null,
        skuSnapshot: null,
        itemKindSnapshot: null,
        quantityScaled: ComponentCostEstimator::quantityToScaled('1'),
        uomSnapshot: 'each',
        calculatedUnitPriceCents: 1000,
        finalUnitPriceCents: 1000,
        lineDiscountMethod: QuoteLineDiscountMethod::None,
        lineDiscountValue: 0,
        isTaxable: true,
        priceOverride: false,
        overrideReason: null,
        belowMinimum: false,
        approvalRequired: true,
        approvalReasons: ['below_minimum'],
        materialCostMicroUnits: 2500,
        laborCostMicroUnits: 1000,
        overheadCostMicroUnits: 0,
        totalCostMicroUnits: 3500,
        pricingMethodSnapshot: null,
        markupBasisPointsSnapshot: 5000,
        marginBasisPointsSnapshot: 4000,
        pricingVersionSnapshot: null,
        componentsVersionSnapshot: null,
        componentCostBreakdown: ['rows' => [['cost' => 1]]],
        pricingInputSnapshot: null,
        pricingResultSnapshot: null,
    );
    $totals = (new QuoteTotalsCalculator)->calculate([$line]);

    $payload = $integrity->buildCustomerPayload($totals, [$line], 'Pay within 30 days.');
    $encoded = $integrity->canonicalJson($payload);

    foreach (CustomerSafeQuoteProjection::forbiddenKeys() as $key) {
        expect($encoded)->not->toContain('"'.$key.'"');
    }

    $reordered = [
        'terms_checksum' => $payload['terms_checksum'],
        'document_type' => $payload['document_type'],
        'totals' => $payload['totals'],
    ];

    expect($integrity->payloadChecksum($payload))
        ->toBe($integrity->payloadChecksum($reordered))
        ->and($integrity->termsChecksum('Pay within 30 days.'))
        ->toBe($payload['terms_checksum'])
        ->and($integrity->fileChecksum('pdf-bytes'))->toHaveLength(64);
});

test('customer quote document integrity binds responses to the shown document checksum', function () {
    $integrity = new CustomerQuoteDocumentIntegrity;
    $checksum = $integrity->payloadChecksum(['a' => 1, 'b' => ['z' => 2, 'y' => 3]]);

    $integrity->assertResponseMatchesDocument($checksum, $checksum);

    expect(fn () => $integrity->assertResponseMatchesDocument('deadbeef', $checksum))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $integrity->assertNoForbiddenKeys([
        'totals' => ['margin_basis_points' => 1000],
    ]))->toThrow(InvalidArgumentException::class);
});
