<?php

namespace App\Support\Quotes\Approval;

/**
 * Facts the caller has already gathered about a quote and its customer.
 *
 * The evaluator does not look anything up, so relationship status and deal
 * history arrive as plain booleans resolved by the caller.
 */
final readonly class QuoteApprovalEvaluationInput
{
    /**
     * @param  int  $finalPretaxAmountCents  quote total before tax
     * @param  bool  $organizationCompanyIsNew  relationship status is "new"
     * @param  bool  $hasPreviouslyWonDeal  the customer has at least one won deal
     * @param  bool  $organizationCompanyIsFlagged  the customer record is flagged
     * @param  list<string>  $additionalReasons  caller-supplied reasons merged into the result
     */
    public function __construct(
        public int $finalPretaxAmountCents,
        public bool $organizationCompanyIsNew = false,
        public bool $hasPreviouslyWonDeal = false,
        public bool $organizationCompanyIsFlagged = false,
        public bool $hasCustomLine = false,
        public bool $hasPriceOverride = false,
        public bool $hasBelowMinimumLine = false,
        public bool $hasMarginOverride = false,
        public bool $hasQuoteDiscount = false,
        public bool $hasLineDiscount = false,
        public bool $manualEscalationRequested = false,
        public array $additionalReasons = [],
    ) {}
}
