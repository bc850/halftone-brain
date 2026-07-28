<?php

namespace App\Http\Resources;

use App\Enums\QuoteRevisionStatus;
use App\Models\OrganizationProduct;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionLineItem;
use App\Support\Money;

/**
 * Internal quote revision payload.
 *
 * Every money total on a revision is pre-tax until the tax engine resolves it, so the
 * payload names totals accordingly and carries explicit unresolved-tax messaging flags.
 * Cost and margin keys are omitted entirely without cost visibility.
 */
final class QuoteRevisionResource
{
    public const TAX_PENDING_MESSAGE = 'Tax has not been calculated. This quote cannot be approved or sent yet.';

    /**
     * @param  iterable<int, QuoteRevision>  $revisions
     * @return list<array<string, mixed>>
     */
    public static function collection(iterable $revisions): array
    {
        $payload = [];

        foreach ($revisions as $revision) {
            $payload[] = self::summary($revision);
        }

        return $payload;
    }

    /**
     * Revision-history sized payload: no lines, adjustments, or party snapshot.
     *
     * @return array<string, mixed>
     */
    public static function summary(QuoteRevision $revision): array
    {
        return [
            'id' => $revision->id,
            'quote_id' => $revision->quote_id,
            'revision_number' => $revision->revision_number,
            'source_revision_id' => $revision->source_revision_id,
            'status' => $revision->status->value,
            'is_draft' => $revision->status === QuoteRevisionStatus::Draft,
            'lock_version' => $revision->lock_version,
            'currency_code' => $revision->currency_code,
            'issue_date' => $revision->issue_date?->toDateString(),
            'expiration_date' => $revision->expiration_date?->toDateString(),
            'pretax_total' => self::dollars($revision->grand_total_cents - $revision->tax_cents),
            'pretax_total_cents' => $revision->grand_total_cents - $revision->tax_cents,
            'approval_required' => $revision->approval_required,
            ...self::taxFlags($revision),
            'created_at' => $revision->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, OrganizationProduct>  $liveCatalog  Keyed by organization product id.
     * @return array<string, mixed>
     */
    public static function make(QuoteRevision $revision, bool $canViewCost, array $liveCatalog = []): array
    {
        $revision->loadMissing(['lineItems', 'adjustments', 'partySnapshot']);

        $payload = [
            ...self::summary($revision),
            'introduction' => $revision->introduction,
            'terms_text' => $revision->terms_text,
            'customer_notes' => $revision->customer_notes,
            'internal_notes' => $revision->internal_notes,
            'subtotal' => self::dollars($revision->subtotal_cents),
            'discount_total' => self::dollars($revision->discount_cents),
            'provisional_taxable_amount' => self::dollars($revision->taxable_amount_cents),
            'requested_deposit' => $revision->requested_deposit_cents === null
                ? null
                : self::dollars($revision->requested_deposit_cents),
            'approval_reasons' => self::reasons($revision->approval_reason_snapshot),
            'lines' => QuoteLineItemResource::collection($revision->lineItems, $canViewCost, $liveCatalog),
            'adjustments' => QuoteAdjustmentResource::collection($revision->adjustments),
            'party_snapshot' => QuotePartySnapshotResource::make($revision->partySnapshot),
        ];

        if ($canViewCost) {
            $payload['cost_summary'] = self::costSummary($revision);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function taxFlags(QuoteRevision $revision): array
    {
        $unresolved = ! $revision->tax_calculation_status->isResolved();

        return [
            'tax_calculation_status' => $revision->tax_calculation_status->value,
            'tax_unresolved' => $unresolved,
            'tax_pending' => $revision->tax_calculation_status->blocksCustomerFinalization(),
            // Every total on a revision stays pre-tax until the engine resolves a
            // position, so a grand total is only meaningful once it has.
            'totals_are_pretax' => $unresolved,
            'tax' => self::dollars($revision->tax_cents),
            'tax_cents' => $revision->tax_cents,
            'grand_total' => $unresolved ? null : self::dollars($revision->grand_total_cents),
            'grand_total_cents' => $unresolved ? null : $revision->grand_total_cents,
            'tax_message' => $unresolved ? self::TAX_PENDING_MESSAGE : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function costSummary(QuoteRevision $revision): array
    {
        $costCents = 0;
        $hasCost = false;

        foreach ($revision->lineItems as $line) {
            /** @var QuoteRevisionLineItem $line */
            if ($line->total_cost_micro_units === null) {
                continue;
            }

            $extended = QuoteLineItemResource::extendedCostCents(
                $line,
                Money::microUnitsToCents($line->total_cost_micro_units),
            );

            if ($extended === null) {
                continue;
            }

            $costCents += $extended;
            $hasCost = true;
        }

        $marginCents = $revision->grand_total_cents - $costCents;

        return [
            'total_cost' => self::dollars($costCents),
            'margin_amount' => self::dollars($marginCents),
            'margin_percent' => $revision->grand_total_cents > 0
                ? bcdiv(bcmul((string) $marginCents, '100', 4), (string) $revision->grand_total_cents, 2)
                : null,
            // Custom lines carry no catalog cost, so a partial roll-up must say so.
            'covers_all_lines' => $hasCost && self::allFinancialLinesCosted($revision),
        ];
    }

    private static function allFinancialLinesCosted(QuoteRevision $revision): bool
    {
        foreach ($revision->lineItems as $line) {
            /** @var QuoteRevisionLineItem $line */
            if ($line->line_type->isFinancial() && $line->total_cost_micro_units === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return list<string>
     */
    private static function reasons(?array $snapshot): array
    {
        $reasons = $snapshot['reasons'] ?? null;

        if (! is_array($reasons)) {
            return [];
        }

        return array_values(array_filter($reasons, static fn (mixed $reason): bool => is_string($reason)));
    }

    private static function dollars(int $cents): string
    {
        return $cents < 0
            ? '-'.Money::centsToDollars(abs($cents))
            : Money::centsToDollars($cents);
    }
}
