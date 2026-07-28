<?php

use App\Enums\QuoteTaxCalculationOutcome;
use App\Enums\QuoteTaxCalculationStatus;
use App\Support\Quotes\Approval\QuoteApprovalEvaluationInput;
use App\Support\Quotes\Approval\QuoteApprovalEvaluator;
use App\Support\Quotes\QuoteApprovalReasonAggregator;

/**
 * A quote that trips nothing: under the threshold, established customer, no
 * overrides. Individual tests override only the fact under examination.
 */
function approvalInput(array $overrides = []): QuoteApprovalEvaluationInput
{
    $defaults = [
        'finalPretaxAmountCents' => 100_000,
        'organizationCompanyIsNew' => false,
        'hasPreviouslyWonDeal' => true,
    ];

    return new QuoteApprovalEvaluationInput(...[...$defaults, ...$overrides]);
}

test('a quiet quote from an established customer needs no approval', function () {
    $result = (new QuoteApprovalEvaluator)->evaluate(approvalInput());

    expect($result->requiresApproval)->toBeFalse()
        ->and($result->reasons)->toBe([])
        ->and($result->explanations)->toBe([])
        ->and($result->meetsMonetaryThreshold)->toBeFalse()
        ->and($result->thresholdBasisCents)->toBe(100_000);
});

test('the monetary threshold is strict at fifteen hundred dollars', function (int $cents, bool $expected) {
    $result = (new QuoteApprovalEvaluator)->evaluate(approvalInput(['finalPretaxAmountCents' => $cents]));

    expect($result->meetsMonetaryThreshold)->toBe($expected)
        ->and($result->hasReason(QuoteApprovalReasonAggregator::REASON_OVER_THRESHOLD))->toBe($expected);
})->with([
    'one cent under' => [149_999, false],
    'exactly at the threshold' => [150_000, false],
    'one cent over' => [150_001, true],
]);

test('the threshold constant matches the totals calculator', function () {
    expect(QuoteApprovalEvaluator::APPROVAL_THRESHOLD_CENTS)->toBe(150_000);
});

test('an unproven customer relationship triggers approval', function (bool $isNew, bool $hasWon, bool $expected) {
    $result = (new QuoteApprovalEvaluator)->evaluate(approvalInput([
        'organizationCompanyIsNew' => $isNew,
        'hasPreviouslyWonDeal' => $hasWon,
    ]));

    expect($result->hasReason(QuoteApprovalReasonAggregator::REASON_NEW_CUSTOMER))->toBe($expected);
})->with([
    'relationship status is new' => [true, true, true],
    'no previously won deal' => [false, false, true],
    'both signals fire but report once' => [true, false, true],
    'established with a won deal' => [false, true, false],
]);

test('both new-customer signals collapse into a single reason', function () {
    $result = (new QuoteApprovalEvaluator)->evaluate(approvalInput([
        'organizationCompanyIsNew' => true,
        'hasPreviouslyWonDeal' => false,
    ]));

    expect($result->reasons)->toBe([QuoteApprovalReasonAggregator::REASON_NEW_CUSTOMER]);
});

test('each quote-level trigger produces its own stable key', function (string $field, string $expectedReason) {
    $result = (new QuoteApprovalEvaluator)->evaluate(approvalInput([$field => true]));

    expect($result->requiresApproval)->toBeTrue()
        ->and($result->reasons)->toBe([$expectedReason])
        ->and($result->explanations[$expectedReason])->not->toBe('');
})->with([
    'flagged customer' => ['organizationCompanyIsFlagged', QuoteApprovalReasonAggregator::REASON_FLAGGED_CUSTOMER],
    'custom line' => ['hasCustomLine', QuoteApprovalReasonAggregator::REASON_CUSTOM_LINE],
    'price override' => ['hasPriceOverride', QuoteApprovalReasonAggregator::REASON_PRICE_OVERRIDE],
    'below minimum' => ['hasBelowMinimumLine', QuoteApprovalReasonAggregator::REASON_BELOW_MINIMUM],
    'margin override' => ['hasMarginOverride', QuoteApprovalReasonAggregator::REASON_MARGIN_OVERRIDE],
    'line discount' => ['hasLineDiscount', QuoteApprovalReasonAggregator::REASON_LINE_DISCOUNT],
    'quote discount' => ['hasQuoteDiscount', QuoteApprovalReasonAggregator::REASON_QUOTE_DISCOUNT],
    'manual escalation' => ['manualEscalationRequested', QuoteApprovalReasonAggregator::REASON_MANUAL_ESCALATION],
]);

test('combined triggers are deduped and returned in a stable order', function () {
    $result = (new QuoteApprovalEvaluator)->evaluate(approvalInput([
        'finalPretaxAmountCents' => 500_000,
        'organizationCompanyIsNew' => true,
        'hasPreviouslyWonDeal' => false,
        'organizationCompanyIsFlagged' => true,
        'hasCustomLine' => true,
        'hasPriceOverride' => true,
        'hasBelowMinimumLine' => true,
        'hasMarginOverride' => true,
        'hasLineDiscount' => true,
        'hasQuoteDiscount' => true,
        'manualEscalationRequested' => true,
        'additionalReasons' => [
            QuoteApprovalReasonAggregator::REASON_CUSTOM_LINE,
            'custom_workflow_rule',
        ],
    ]));

    expect($result->requiresApproval)->toBeTrue()
        ->and($result->reasons)->toBe([
            QuoteApprovalReasonAggregator::REASON_OVER_THRESHOLD,
            QuoteApprovalReasonAggregator::REASON_NEW_CUSTOMER,
            QuoteApprovalReasonAggregator::REASON_FLAGGED_CUSTOMER,
            QuoteApprovalReasonAggregator::REASON_CUSTOM_LINE,
            QuoteApprovalReasonAggregator::REASON_PRICE_OVERRIDE,
            QuoteApprovalReasonAggregator::REASON_BELOW_MINIMUM,
            QuoteApprovalReasonAggregator::REASON_MARGIN_OVERRIDE,
            QuoteApprovalReasonAggregator::REASON_LINE_DISCOUNT,
            QuoteApprovalReasonAggregator::REASON_QUOTE_DISCOUNT,
            QuoteApprovalReasonAggregator::REASON_MANUAL_ESCALATION,
            'custom_workflow_rule',
        ])
        ->and($result->explanations)->toHaveKey('custom_workflow_rule');
});

test('unresolved tax blocks approval and resolved tax does not', function (QuoteTaxCalculationStatus|QuoteTaxCalculationOutcome $status, bool $blocks) {
    expect((new QuoteApprovalEvaluator)->taxBlocksApproval($status))->toBe($blocks);
})->with([
    'pending status' => [QuoteTaxCalculationStatus::Pending, true],
    'review required status' => [QuoteTaxCalculationStatus::ReviewRequired, true],
    'calculated status' => [QuoteTaxCalculationStatus::Calculated, false],
    'exempt status' => [QuoteTaxCalculationStatus::Exempt, false],
    'review required outcome' => [QuoteTaxCalculationOutcome::ReviewRequired, true],
    'calculated outcome' => [QuoteTaxCalculationOutcome::Calculated, false],
    'exempt outcome' => [QuoteTaxCalculationOutcome::Exempt, false],
]);

test('approval readiness needs resolved tax and a granted decision when triggers fired', function () {
    $evaluator = new QuoteApprovalEvaluator;
    $clean = $evaluator->evaluate(approvalInput());
    $triggered = $evaluator->evaluate(approvalInput(['finalPretaxAmountCents' => 500_000]));

    expect($evaluator->canBecomeApproved(QuoteTaxCalculationStatus::Calculated, $clean))->toBeTrue()
        ->and($evaluator->canBecomeApproved(QuoteTaxCalculationStatus::Exempt, $clean))->toBeTrue()
        ->and($evaluator->canBecomeApproved(QuoteTaxCalculationStatus::Pending, $clean))->toBeFalse()
        ->and($evaluator->canBecomeApproved(QuoteTaxCalculationStatus::ReviewRequired, $clean))->toBeFalse()
        ->and($evaluator->canBecomeApproved(QuoteTaxCalculationStatus::Calculated, $triggered))->toBeFalse()
        ->and($evaluator->canBecomeApproved(QuoteTaxCalculationStatus::Calculated, $triggered, true))->toBeTrue()
        ->and($evaluator->canBecomeApproved(QuoteTaxCalculationStatus::Pending, $triggered, true))->toBeFalse();
});
