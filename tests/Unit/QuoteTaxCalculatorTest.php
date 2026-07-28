<?php

use App\Enums\QuoteAdjustmentMethod;
use App\Enums\QuoteAdjustmentType;
use App\Enums\QuoteLineDiscountMethod;
use App\Enums\QuoteLineType;
use App\Enums\QuoteTaxCalculationOutcome;
use App\Enums\QuoteTaxCalculationSource;
use App\Enums\QuoteTaxCalculationStatus;
use App\Enums\TaxCertificateVerificationStatus;
use App\Enums\TaxExemptionCategory;
use App\Models\OrganizationCompanyTaxCertificate;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Quotes\Snapshots\CustomerSafeTaxProjection;
use App\Support\Quotes\Tax\InvalidQuoteTaxCalculationException;
use App\Support\Quotes\Tax\QuoteTaxCalculationInput;
use App\Support\Quotes\Tax\QuoteTaxCalculator;
use App\Support\Quotes\Totals\InvalidQuoteTotalsException;
use App\Support\Quotes\Totals\QuoteAdjustmentCalculationInput;
use App\Support\Quotes\Totals\QuoteDiscountTaxAllocator;
use App\Support\Quotes\Totals\QuoteLineCalculationInput;
use App\Support\Quotes\Totals\QuoteTotalsCalculator;
use App\Support\Tax\OrganizationCompanyTaxCertificateApplicability;
use App\Support\Tax\TaxCertificateApplicability;
use Tests\TestCase;

// Certificate applicability reads Eloquent date casts, which need a booted app.
// No test here touches the database.
uses(TestCase::class);

const TAX_TEST_JURISDICTION = ['jurisdiction_code' => 'ga-fulton', 'display_name' => 'Fulton County, GA'];

function taxInput(array $overrides = []): QuoteTaxCalculationInput
{
    $defaults = [
        'taxableBasisCents' => 100_000,
        'calculatorVersion' => 'test-calculator-1',
        'ratePpm' => 80_000,
        'jurisdictionSnapshot' => TAX_TEST_JURISDICTION,
    ];

    return new QuoteTaxCalculationInput(...[...$defaults, ...$overrides]);
}

function taxTestCertificate(array $overrides = []): OrganizationCompanyTaxCertificate
{
    $certificate = new OrganizationCompanyTaxCertificate;

    $certificate->forceFill([...[
        'id' => 42,
        'exemption_category' => TaxExemptionCategory::Resale->value,
        'jurisdiction_state' => 'GA',
        'certificate_form_type' => 'ST-5',
        'certificate_number' => 'CERT-000123',
        'effective_date' => '2026-01-01',
        'expiration_date' => null,
        'verification_status' => TaxCertificateVerificationStatus::Verified->value,
    ], ...$overrides]);

    return $certificate;
}

function taxTestLineInput(array $overrides = []): QuoteLineCalculationInput
{
    $defaults = [
        'key' => 'line-1',
        'lineType' => QuoteLineType::Custom,
        'nameSnapshot' => 'Custom Item',
        'customerDescriptionSnapshot' => null,
        'internalDescriptionSnapshot' => null,
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
        'materialCostMicroUnits' => null,
        'laborCostMicroUnits' => null,
        'overheadCostMicroUnits' => null,
        'totalCostMicroUnits' => null,
        'pricingMethodSnapshot' => null,
        'markupBasisPointsSnapshot' => null,
        'marginBasisPointsSnapshot' => null,
        'pricingVersionSnapshot' => null,
        'componentsVersionSnapshot' => null,
        'componentCostBreakdown' => null,
        'pricingInputSnapshot' => null,
        'pricingResultSnapshot' => null,
    ];

    return new QuoteLineCalculationInput(...[...$defaults, ...$overrides]);
}

test('parts-per-million rate applies to the taxable basis', function () {
    $result = (new QuoteTaxCalculator)->calculate(taxInput());

    expect($result->outcome)->toBe(QuoteTaxCalculationOutcome::Calculated)
        ->and($result->taxCents)->toBe(8_000)
        ->and($result->ratePpm)->toBe(80_000)
        ->and($result->source)->toBe(QuoteTaxCalculationSource::ConfiguredRate)
        ->and($result->isOverride)->toBeFalse()
        ->and($result->reviewReasons)->toBe([])
        ->and($result->revisionStatus())->toBe(QuoteTaxCalculationStatus::Calculated);
});

test('tax rounds half up at the cent boundary', function (int $basis, int $ratePpm, int $expected) {
    $result = (new QuoteTaxCalculator)->calculate(taxInput([
        'taxableBasisCents' => $basis,
        'ratePpm' => $ratePpm,
    ]));

    expect($result->taxCents)->toBe($expected);
})->with([
    'exactly half rounds up' => [10, 50_000, 1],
    'just below half rounds down' => [10, 49_999, 0],
    'just above half rounds up' => [10, 50_001, 1],
    'zero rate yields zero tax' => [100_000, 0, 0],
    'large basis stays exact' => [123_456_789, 87_500, 10_802_469],
]);

test('a zero basis with a configured rate is still a calculated outcome', function () {
    $result = (new QuoteTaxCalculator)->calculate(taxInput(['taxableBasisCents' => 0]));

    expect($result->outcome)->toBe(QuoteTaxCalculationOutcome::Calculated)
        ->and($result->taxCents)->toBe(0);
});

test('missing facts produce review_required rather than zero tax', function (array $overrides, string $expectedReason) {
    $result = (new QuoteTaxCalculator)->calculate(taxInput($overrides));

    expect($result->outcome)->toBe(QuoteTaxCalculationOutcome::ReviewRequired)
        ->and($result->taxCents)->toBe(0)
        ->and($result->reviewReasons)->toContain($expectedReason)
        ->and($result->revisionStatus())->toBe(QuoteTaxCalculationStatus::ReviewRequired)
        ->and($result->isResolved())->toBeFalse();
})->with([
    'no configured rate' => [['ratePpm' => null], QuoteTaxCalculator::REASON_MISSING_CONFIGURED_RATE],
    'no jurisdiction' => [['jurisdictionSnapshot' => null], QuoteTaxCalculator::REASON_MISSING_JURISDICTION],
    'ambiguous jurisdiction' => [['jurisdictionAmbiguous' => true], QuoteTaxCalculator::REASON_AMBIGUOUS_JURISDICTION],
    'calculation disabled' => [['taxCalculationEnabled' => false], QuoteTaxCalculator::REASON_TAX_CALCULATION_DISABLED],
]);

test('verified applicable evidence produces an exempt outcome', function () {
    $result = (new QuoteTaxCalculator)->calculate(taxInput([
        'exemptionClaimed' => true,
        'certificateApplicability' => new TaxCertificateApplicability(true, [], 42),
    ]));

    expect($result->outcome)->toBe(QuoteTaxCalculationOutcome::Exempt)
        ->and($result->taxCents)->toBe(0)
        ->and($result->source)->toBe(QuoteTaxCalculationSource::VerifiedExemption)
        ->and($result->certificateId)->toBe(42)
        ->and($result->revisionStatus())->toBe(QuoteTaxCalculationStatus::Exempt);
});

test('an unsupported exemption claim never becomes a silent exemption', function () {
    $applicability = new TaxCertificateApplicability(
        false,
        [OrganizationCompanyTaxCertificateApplicability::REASON_PENDING_VERIFICATION],
        42,
    );

    $result = (new QuoteTaxCalculator)->calculate(taxInput([
        'exemptionClaimed' => true,
        'certificateApplicability' => $applicability,
    ]));

    expect($result->outcome)->toBe(QuoteTaxCalculationOutcome::ReviewRequired)
        ->and($result->taxCents)->toBe(0)
        ->and($result->reviewReasons)
        ->toBe([OrganizationCompanyTaxCertificateApplicability::REASON_PENDING_VERIFICATION]);
});

test('claiming exemption with no certificate at all requires review', function () {
    $result = (new QuoteTaxCalculator)->calculate(taxInput(['exemptionClaimed' => true]));

    expect($result->outcome)->toBe(QuoteTaxCalculationOutcome::ReviewRequired)
        ->and($result->reviewReasons)
        ->toBe([OrganizationCompanyTaxCertificateApplicability::REASON_MISSING_CERTIFICATE]);
});

test('a nonprofit category alone does not make a sale exempt', function () {
    $certificate = taxTestCertificate([
        'exemption_category' => TaxExemptionCategory::QualifyingNonprofit->value,
        'verification_status' => TaxCertificateVerificationStatus::Pending->value,
    ]);

    $applicability = (new OrganizationCompanyTaxCertificateApplicability)
        ->evaluate($certificate, 'GA', '2026-06-01');

    expect($applicability->isApplicable)->toBeFalse()
        ->and($applicability->reasons)
        ->toBe([OrganizationCompanyTaxCertificateApplicability::REASON_PENDING_VERIFICATION]);

    $result = (new QuoteTaxCalculator)->calculate(taxInput([
        'exemptionClaimed' => true,
        'certificateApplicability' => $applicability,
    ]));

    expect($result->outcome)->toBe(QuoteTaxCalculationOutcome::ReviewRequired)
        ->and($result->taxCents)->toBe(0);
});

test('a verified nonprofit certificate in window and jurisdiction is applicable', function () {
    $certificate = taxTestCertificate([
        'exemption_category' => TaxExemptionCategory::QualifyingNonprofit->value,
        'verification_status' => TaxCertificateVerificationStatus::Verified->value,
    ]);

    $applicability = (new OrganizationCompanyTaxCertificateApplicability)
        ->evaluate($certificate, 'ga', '2026-06-01');

    expect($applicability->isApplicable)->toBeTrue()
        ->and($applicability->reasons)->toBe([])
        ->and($applicability->certificateId)->toBe(42);
});

test('certificate applicability reports every unmet condition', function (array $overrides, string $state, string $asOf, array $expected) {
    $applicability = (new OrganizationCompanyTaxCertificateApplicability)
        ->evaluate(taxTestCertificate($overrides), $state, $asOf);

    expect($applicability->isApplicable)->toBeFalse()
        ->and($applicability->reasons)->toBe($expected)
        ->and($applicability->requiresReview())->toBeTrue();
})->with([
    'rejected' => [
        ['verification_status' => TaxCertificateVerificationStatus::Rejected->value],
        'GA',
        '2026-06-01',
        [OrganizationCompanyTaxCertificateApplicability::REASON_REJECTED],
    ],
    'revoked' => [
        ['verification_status' => TaxCertificateVerificationStatus::Revoked->value],
        'GA',
        '2026-06-01',
        [OrganizationCompanyTaxCertificateApplicability::REASON_REVOKED],
    ],
    'past expiration date' => [
        ['expiration_date' => '2026-03-31'],
        'GA',
        '2026-06-01',
        [OrganizationCompanyTaxCertificateApplicability::REASON_EXPIRED],
    ],
    'before effective date' => [
        ['effective_date' => '2026-07-01'],
        'GA',
        '2026-06-01',
        [OrganizationCompanyTaxCertificateApplicability::REASON_NOT_YET_EFFECTIVE],
    ],
    'different jurisdiction' => [
        [],
        'FL',
        '2026-06-01',
        [OrganizationCompanyTaxCertificateApplicability::REASON_JURISDICTION_MISMATCH],
    ],
    'expired and wrong jurisdiction' => [
        ['verification_status' => TaxCertificateVerificationStatus::Expired->value],
        'FL',
        '2026-06-01',
        [
            OrganizationCompanyTaxCertificateApplicability::REASON_EXPIRED,
            OrganizationCompanyTaxCertificateApplicability::REASON_JURISDICTION_MISMATCH,
        ],
    ],
]);

test('a missing certificate is reported without touching the database', function () {
    $applicability = (new OrganizationCompanyTaxCertificateApplicability)->evaluate(null, 'GA', '2026-06-01');

    expect($applicability->isApplicable)->toBeFalse()
        ->and($applicability->certificateId)->toBeNull()
        ->and($applicability->reasons)
        ->toBe([OrganizationCompanyTaxCertificateApplicability::REASON_MISSING_CERTIFICATE]);
});

test('a manual override records its own tax amount, source, and reason', function () {
    $result = (new QuoteTaxCalculator)->calculate(taxInput([
        'overrideTaxCents' => 1_234,
        'overrideReason' => 'Agreed in writing with the customer for this shipment.',
    ]));

    expect($result->outcome)->toBe(QuoteTaxCalculationOutcome::Calculated)
        ->and($result->taxCents)->toBe(1_234)
        ->and($result->source)->toBe(QuoteTaxCalculationSource::ManualOverride)
        ->and($result->isOverride)->toBeTrue()
        ->and($result->overrideReason)->toBe('Agreed in writing with the customer for this shipment.');
});

test('invalid inputs are rejected instead of guessed', function (array $overrides, string $message) {
    expect(fn () => (new QuoteTaxCalculator)->calculate(taxInput($overrides)))
        ->toThrow(InvalidQuoteTaxCalculationException::class, $message);
})->with([
    'override without reason' => [
        ['overrideTaxCents' => 500],
        'requires a reason',
    ],
    'override with blank reason' => [
        ['overrideTaxCents' => 500, 'overrideReason' => '   '],
        'requires a reason',
    ],
    'negative override' => [
        ['overrideTaxCents' => -1, 'overrideReason' => 'why'],
        'cannot be negative',
    ],
    'negative basis' => [
        ['taxableBasisCents' => -1],
        'Taxable basis cannot be negative',
    ],
]);

test('quote discount splits across taxable and nontaxable line nets', function () {
    $allocation = (new QuoteDiscountTaxAllocator)->allocate(
        taxableLineNetCents: 6_667,
        nontaxableLineNetCents: 3_333,
        quoteDiscountCents: 1_000,
    );

    expect($allocation->taxableDiscountAllocationCents)->toBe(667)
        ->and($allocation->nontaxableDiscountAllocationCents)->toBe(333)
        ->and($allocation->taxableDiscountAllocationCents + $allocation->nontaxableDiscountAllocationCents)
        ->toBe($allocation->quoteDiscountCents)
        ->and($allocation->taxableBasisCents)->toBe(6_000);
});

test('the nontaxable share absorbs the rounding remainder exactly', function () {
    $allocation = (new QuoteDiscountTaxAllocator)->allocate(
        taxableLineNetCents: 1,
        nontaxableLineNetCents: 2,
        quoteDiscountCents: 1,
    );

    // 1 × 1 / 3 = 0.333… rounds to 0, so the whole cent lands on the nontaxable side.
    expect($allocation->taxableDiscountAllocationCents)->toBe(0)
        ->and($allocation->nontaxableDiscountAllocationCents)->toBe(1)
        ->and($allocation->taxableBasisCents)->toBe(1);
});

test('no eligible line net means nothing to allocate', function () {
    $allocation = (new QuoteDiscountTaxAllocator)->allocate(
        taxableLineNetCents: 0,
        nontaxableLineNetCents: 0,
        quoteDiscountCents: 0,
        taxablePositiveAdjustmentCents: 500,
    );

    expect($allocation->taxableDiscountAllocationCents)->toBe(0)
        ->and($allocation->nontaxableDiscountAllocationCents)->toBe(0)
        ->and($allocation->taxableBasisCents)->toBe(500);
});

test('taxable basis clamps at zero before taxable charges are added', function () {
    $allocation = (new QuoteDiscountTaxAllocator)->allocate(
        taxableLineNetCents: 1_000,
        nontaxableLineNetCents: 0,
        quoteDiscountCents: 1_000,
        taxablePositiveAdjustmentCents: 250,
    );

    expect($allocation->taxableDiscountAllocationCents)->toBe(1_000)
        ->and($allocation->taxableBasisCents)->toBe(250);
});

test('a discount larger than the eligible line net is rejected', function () {
    expect(fn () => (new QuoteDiscountTaxAllocator)->allocate(
        taxableLineNetCents: 100,
        nontaxableLineNetCents: 100,
        quoteDiscountCents: 300,
    ))->toThrow(InvalidQuoteTotalsException::class, 'cannot exceed eligible line net total');
});

test('allocation reads taxable and nontaxable nets straight from a totals result', function () {
    $totals = (new QuoteTotalsCalculator)->calculate(
        [
            taxTestLineInput([
                'key' => 'taxable',
                'quantityScaled' => ComponentCostEstimator::quantityToScaled('3'),
                'finalUnitPriceCents' => 10_000,
                'isTaxable' => true,
            ]),
            taxTestLineInput([
                'key' => 'nontaxable',
                'quantityScaled' => ComponentCostEstimator::quantityToScaled('1'),
                'finalUnitPriceCents' => 10_000,
                'isTaxable' => false,
            ]),
            taxTestLineInput([
                'key' => 'section',
                'lineType' => QuoteLineType::Section,
                'quantityScaled' => 0,
                'calculatedUnitPriceCents' => 0,
                'finalUnitPriceCents' => 0,
                'isTaxable' => false,
            ]),
        ],
        [
            new QuoteAdjustmentCalculationInput(
                key: 'discount',
                adjustmentType: QuoteAdjustmentType::QuoteDiscount,
                descriptionSnapshot: 'Volume discount',
                method: QuoteAdjustmentMethod::Fixed,
                inputValue: 4_000,
                isTaxable: false,
                approvalRequired: false,
            ),
            new QuoteAdjustmentCalculationInput(
                key: 'shipping',
                adjustmentType: QuoteAdjustmentType::Shipping,
                descriptionSnapshot: 'Freight',
                method: QuoteAdjustmentMethod::Fixed,
                inputValue: 1_500,
                isTaxable: true,
                approvalRequired: false,
            ),
        ],
    );

    $allocation = (new QuoteDiscountTaxAllocator)->allocateFromTotals($totals);

    expect($allocation->taxableLineNetCents)->toBe(30_000)
        ->and($allocation->nontaxableLineNetCents)->toBe(10_000)
        ->and($allocation->taxableDiscountAllocationCents)->toBe(3_000)
        ->and($allocation->nontaxableDiscountAllocationCents)->toBe(1_000)
        ->and($allocation->taxablePositiveAdjustmentCents)->toBe(1_500)
        ->and($allocation->taxableBasisCents)->toBe(28_500);

    $result = (new QuoteTaxCalculator)->calculate(taxInput([
        'taxableBasisCents' => $allocation->taxableBasisCents,
        'ratePpm' => 80_000,
    ]));

    expect($result->taxCents)->toBe(2_280);
});

test('the customer-safe tax projection hides amounts and reasons until tax resolves', function () {
    $projection = new CustomerSafeTaxProjection;

    $calculated = $projection->fromResult((new QuoteTaxCalculator)->calculate(taxInput()));
    $needsReview = $projection->fromResult((new QuoteTaxCalculator)->calculate(taxInput(['ratePpm' => null])));

    expect($calculated)->toBe([
        'tax_status' => 'calculated',
        'tax_resolved' => true,
        'tax_cents' => 8_000,
        'taxable_basis_cents' => 100_000,
        'jurisdiction_display_name' => 'Fulton County, GA',
    ])
        ->and($needsReview['tax_resolved'])->toBeFalse()
        ->and($needsReview['tax_cents'])->toBeNull()
        ->and(array_intersect(array_keys($needsReview), CustomerSafeTaxProjection::forbiddenKeys()))->toBe([]);
});
