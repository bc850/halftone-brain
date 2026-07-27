<?php

namespace App\Support\Quotes;

use App\Enums\QuoteAdjustmentType;
use App\Enums\QuoteLineDiscountMethod;
use App\Enums\QuoteLineType;
use App\Models\QuoteRevisionAdjustment;
use App\Models\QuoteRevisionLineItem;
use App\Support\Quotes\Totals\QuoteTotalsResult;

/**
 * Aggregates line, adjustment, and monetary approval triggers into the
 * `approval_reason_snapshot` payload persisted on a quote revision.
 *
 * Customer-risk reasons (new/flagged customer) arrive in 2C through
 * `$additionalReasons` so line-level aggregation never has to be rewritten.
 */
final class QuoteApprovalReasonAggregator
{
    public const REASON_OVER_THRESHOLD = 'over_threshold';

    public const REASON_CUSTOM_LINE = 'custom_line';

    public const REASON_PRICE_OVERRIDE = 'price_override';

    public const REASON_BELOW_MINIMUM = 'below_minimum';

    public const REASON_MARGIN_OVERRIDE = 'margin_override';

    public const REASON_QUOTE_DISCOUNT = 'quote_discount';

    public const REASON_LINE_DISCOUNT = 'line_discount';

    /**
     * Stable presentation order for known reasons; unknown reasons keep insertion order after these.
     *
     * @var list<string>
     */
    private const REASON_ORDER = [
        self::REASON_OVER_THRESHOLD,
        self::REASON_CUSTOM_LINE,
        self::REASON_PRICE_OVERRIDE,
        self::REASON_BELOW_MINIMUM,
        self::REASON_MARGIN_OVERRIDE,
        self::REASON_LINE_DISCOUNT,
        self::REASON_QUOTE_DISCOUNT,
    ];

    /**
     * @param  iterable<int, QuoteRevisionLineItem>  $lines
     * @param  iterable<int, QuoteRevisionAdjustment>  $adjustments
     * @param  list<string>  $additionalReasons
     * @return array{reasons: list<string>, threshold_basis_cents: int, meets_monetary_threshold: bool}
     */
    public function build(
        iterable $lines,
        iterable $adjustments,
        QuoteTotalsResult $totals,
        array $additionalReasons = [],
    ): array {
        $reasons = [];

        if ($totals->meetsApprovalThreshold) {
            $reasons[] = self::REASON_OVER_THRESHOLD;
        }

        foreach ($lines as $line) {
            foreach ($this->lineReasons($line) as $reason) {
                $reasons[] = $reason;
            }
        }

        foreach ($adjustments as $adjustment) {
            foreach ($this->adjustmentReasons($adjustment) as $reason) {
                $reasons[] = $reason;
            }
        }

        foreach ($additionalReasons as $reason) {
            $reasons[] = $reason;
        }

        return [
            'reasons' => $this->normalize($reasons),
            'threshold_basis_cents' => $totals->approvalThresholdBasisCents,
            'meets_monetary_threshold' => $totals->meetsApprovalThreshold,
        ];
    }

    /**
     * Approval reasons for a single line, suitable for `approval_reason_json`.
     *
     * @return list<string>
     */
    public function lineReasons(QuoteRevisionLineItem $line): array
    {
        $reasons = [];

        if ($line->line_type === QuoteLineType::Custom) {
            $reasons[] = self::REASON_CUSTOM_LINE;
        }

        if ($line->price_override) {
            $reasons[] = self::REASON_PRICE_OVERRIDE;

            if ($this->erodesMargin($line)) {
                $reasons[] = self::REASON_MARGIN_OVERRIDE;
            }
        }

        if ($line->below_minimum) {
            $reasons[] = self::REASON_BELOW_MINIMUM;
        }

        if ($line->line_discount_method !== QuoteLineDiscountMethod::None && $line->line_discount_value > 0) {
            $reasons[] = self::REASON_LINE_DISCOUNT;
        }

        foreach ($this->storedReasons($line->approval_reason_json) as $reason) {
            $reasons[] = $reason;
        }

        return $this->normalize($reasons);
    }

    /**
     * @return list<string>
     */
    public function adjustmentReasons(QuoteRevisionAdjustment $adjustment): array
    {
        $reasons = [];

        if ($adjustment->adjustment_type === QuoteAdjustmentType::QuoteDiscount) {
            $reasons[] = self::REASON_QUOTE_DISCOUNT;
        }

        foreach ($this->storedReasons($adjustment->approval_reason_json) as $reason) {
            $reasons[] = $reason;
        }

        return $this->normalize($reasons);
    }

    /**
     * An override that lands under the calculated price gives up planned margin.
     */
    private function erodesMargin(QuoteRevisionLineItem $line): bool
    {
        return $line->calculated_unit_price_cents !== null
            && $line->final_unit_price_cents !== null
            && $line->final_unit_price_cents < $line->calculated_unit_price_cents;
    }

    /**
     * @param  array<string, mixed>|null  $approvalReasonJson
     * @return list<string>
     */
    private function storedReasons(?array $approvalReasonJson): array
    {
        $stored = $approvalReasonJson['reasons'] ?? null;

        if (! is_array($stored)) {
            return [];
        }

        $reasons = [];
        foreach ($stored as $reason) {
            if (is_string($reason) && $reason !== '') {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    /**
     * @param  list<string>  $reasons
     * @return list<string>
     */
    private function normalize(array $reasons): array
    {
        $unique = array_values(array_unique($reasons));

        usort($unique, static function (string $left, string $right): int {
            $leftIndex = array_search($left, self::REASON_ORDER, true);
            $rightIndex = array_search($right, self::REASON_ORDER, true);
            $leftRank = $leftIndex === false ? PHP_INT_MAX : $leftIndex;
            $rightRank = $rightIndex === false ? PHP_INT_MAX : $rightIndex;

            return $leftRank === $rightRank
                ? strcmp($left, $right)
                : $leftRank <=> $rightRank;
        });

        return $unique;
    }
}
