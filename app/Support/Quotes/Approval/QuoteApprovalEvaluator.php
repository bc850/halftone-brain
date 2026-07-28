<?php

namespace App\Support\Quotes\Approval;

use App\Enums\QuoteTaxCalculationOutcome;
use App\Enums\QuoteTaxCalculationStatus;
use App\Support\Quotes\QuoteApprovalReasonAggregator;
use App\Support\Quotes\Totals\QuoteTotalsCalculator;

/**
 * Pure approval decision engine.
 *
 * Reports whether a quote needs approval and which triggers fired, using stable
 * machine keys plus short explanations safe to show internal users.
 *
 * The monetary trigger is strict: a quote at exactly the threshold does not
 * require approval, only one above it does.
 *
 * No HTTP, auth, TenantContext, Eloquent, database access, audits, or events.
 */
final class QuoteApprovalEvaluator
{
    public const APPROVAL_THRESHOLD_CENTS = QuoteTotalsCalculator::APPROVAL_THRESHOLD_CENTS;

    /**
     * @var array<string, string>
     */
    private const EXPLANATIONS = [
        QuoteApprovalReasonAggregator::REASON_OVER_THRESHOLD => 'Quote total before tax is above the approval threshold.',
        QuoteApprovalReasonAggregator::REASON_NEW_CUSTOMER => 'Customer is new or has no previously won deal.',
        QuoteApprovalReasonAggregator::REASON_FLAGGED_CUSTOMER => 'Customer record is flagged for review.',
        QuoteApprovalReasonAggregator::REASON_CUSTOM_LINE => 'Quote contains a custom line that is not in the catalog.',
        QuoteApprovalReasonAggregator::REASON_PRICE_OVERRIDE => 'A line price was overridden.',
        QuoteApprovalReasonAggregator::REASON_BELOW_MINIMUM => 'A line is priced below its minimum.',
        QuoteApprovalReasonAggregator::REASON_MARGIN_OVERRIDE => 'An override reduced the planned margin.',
        QuoteApprovalReasonAggregator::REASON_LINE_DISCOUNT => 'A line discount needs review.',
        QuoteApprovalReasonAggregator::REASON_QUOTE_DISCOUNT => 'A quote-level discount needs review.',
        QuoteApprovalReasonAggregator::REASON_MANUAL_ESCALATION => 'Approval was requested manually.',
    ];

    public function evaluate(QuoteApprovalEvaluationInput $input): QuoteApprovalEvaluationResult
    {
        $reasons = [];

        $meetsThreshold = $input->finalPretaxAmountCents > self::APPROVAL_THRESHOLD_CENTS;
        if ($meetsThreshold) {
            $reasons[] = QuoteApprovalReasonAggregator::REASON_OVER_THRESHOLD;
        }

        // Either signal alone marks the relationship as unproven, and both collapse
        // to one reason so a brand-new customer is not reported twice.
        if ($input->organizationCompanyIsNew || ! $input->hasPreviouslyWonDeal) {
            $reasons[] = QuoteApprovalReasonAggregator::REASON_NEW_CUSTOMER;
        }

        if ($input->organizationCompanyIsFlagged) {
            $reasons[] = QuoteApprovalReasonAggregator::REASON_FLAGGED_CUSTOMER;
        }

        if ($input->hasCustomLine) {
            $reasons[] = QuoteApprovalReasonAggregator::REASON_CUSTOM_LINE;
        }

        if ($input->hasPriceOverride) {
            $reasons[] = QuoteApprovalReasonAggregator::REASON_PRICE_OVERRIDE;
        }

        if ($input->hasBelowMinimumLine) {
            $reasons[] = QuoteApprovalReasonAggregator::REASON_BELOW_MINIMUM;
        }

        if ($input->hasMarginOverride) {
            $reasons[] = QuoteApprovalReasonAggregator::REASON_MARGIN_OVERRIDE;
        }

        if ($input->hasLineDiscount) {
            $reasons[] = QuoteApprovalReasonAggregator::REASON_LINE_DISCOUNT;
        }

        if ($input->hasQuoteDiscount) {
            $reasons[] = QuoteApprovalReasonAggregator::REASON_QUOTE_DISCOUNT;
        }

        if ($input->manualEscalationRequested) {
            $reasons[] = QuoteApprovalReasonAggregator::REASON_MANUAL_ESCALATION;
        }

        foreach ($input->additionalReasons as $reason) {
            if ($reason !== '') {
                $reasons[] = $reason;
            }
        }

        $reasons = QuoteApprovalReasonAggregator::sortReasons($reasons);

        return new QuoteApprovalEvaluationResult(
            requiresApproval: $reasons !== [],
            reasons: $reasons,
            explanations: $this->explanationsFor($reasons),
            thresholdBasisCents: $input->finalPretaxAmountCents,
            meetsMonetaryThreshold: $meetsThreshold,
        );
    }

    /**
     * An unresolved tax position blocks approval: nobody can approve a total that
     * is not final yet.
     */
    public function taxBlocksApproval(QuoteTaxCalculationStatus|QuoteTaxCalculationOutcome $taxStatus): bool
    {
        return ! $taxStatus->isResolved();
    }

    /**
     * Whether a revision could move to approved right now.
     *
     * Tax must be resolved, and any triggered approval requirement must already
     * have been granted — the pure engine cannot grant it, so the caller reports
     * that through `$hasGrantedApprovalDecision`.
     */
    public function canBecomeApproved(
        QuoteTaxCalculationStatus|QuoteTaxCalculationOutcome $taxStatus,
        QuoteApprovalEvaluationResult $evaluation,
        bool $hasGrantedApprovalDecision = false,
    ): bool {
        if ($this->taxBlocksApproval($taxStatus)) {
            return false;
        }

        return ! $evaluation->requiresApproval || $hasGrantedApprovalDecision;
    }

    /**
     * @param  list<string>  $reasons
     * @return array<string, string>
     */
    private function explanationsFor(array $reasons): array
    {
        $explanations = [];

        foreach ($reasons as $reason) {
            $explanations[$reason] = self::EXPLANATIONS[$reason] ?? 'Approval is required for this quote.';
        }

        return $explanations;
    }
}
