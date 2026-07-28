<?php

namespace App\Http\Resources;

use App\Models\QuoteApprovalRequest;
use App\Support\Money;

/**
 * An approval request as the queue and the builder panel see it.
 *
 * The reasons come from the snapshot recorded when the request was raised, not
 * from a fresh evaluation: an approver must decide on the quote as it was
 * submitted. Both lock versions travel with the payload so a decision can be
 * refused as stale when the quote moved underneath the queue.
 */
final class ApprovalRequestResource
{
    /**
     * @param  iterable<int, QuoteApprovalRequest>  $requests
     * @return list<array<string, mixed>>
     */
    public static function collection(iterable $requests): array
    {
        $payload = [];

        foreach ($requests as $request) {
            $payload[] = self::make($request);
        }

        /** @var list<array<string, mixed>> $payload */
        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function make(?QuoteApprovalRequest $request): ?array
    {
        if ($request === null) {
            return null;
        }

        $request->loadMissing(['quote', 'quoteRevision', 'requestedByMembership.user']);

        $quote = $request->quote;
        $revision = $request->quoteRevision;
        $pretaxCents = $revision === null
            ? 0
            : $revision->grand_total_cents - $revision->tax_cents;

        return [
            'id' => $request->id,
            'quote_id' => $request->quote_id,
            'quote_revision_id' => $request->quote_revision_id,
            'request_version' => $request->request_version,
            'status' => $request->status->value,
            'is_open' => $request->status->isOpen(),
            'reasons' => self::reasons($request),
            'explanations' => self::explanations($request),
            'threshold_basis' => self::dollars(self::thresholdBasisCents($request)),
            'threshold_basis_cents' => self::thresholdBasisCents($request),
            'requested_by' => $request->requestedByMembership?->user?->name,
            'requested_by_membership_id' => $request->requested_by_membership_id,
            'requested_at' => $request->requested_at->toIso8601String(),
            'age_days' => (int) $request->requested_at->diffInDays(now()),
            'resolved_at' => $request->resolved_at?->toIso8601String(),
            'quote_number' => $quote?->quote_number,
            'quote_lock_version' => $quote?->lock_version,
            'revision_number' => $revision?->revision_number,
            'revision_status' => $revision?->status->value,
            'revision_lock_version' => $revision?->lock_version,
            'pretax_total' => self::dollars($pretaxCents),
            'pretax_total_cents' => $pretaxCents,
            'tax_calculation_status' => $revision?->tax_calculation_status->value,
        ];
    }

    /**
     * @return list<string>
     */
    private static function reasons(QuoteApprovalRequest $request): array
    {
        $reasons = $request->rule_snapshot_json['reasons'] ?? null;

        if (! is_array($reasons)) {
            return [];
        }

        return array_values(array_filter($reasons, static fn (mixed $reason): bool => is_string($reason)));
    }

    /**
     * @return array<string, string>
     */
    private static function explanations(QuoteApprovalRequest $request): array
    {
        $explanations = $request->rule_snapshot_json['explanations'] ?? null;

        if (! is_array($explanations)) {
            return [];
        }

        $safe = [];

        foreach ($explanations as $reason => $explanation) {
            if (is_string($reason) && is_string($explanation)) {
                $safe[$reason] = $explanation;
            }
        }

        return $safe;
    }

    private static function thresholdBasisCents(QuoteApprovalRequest $request): int
    {
        $basis = $request->rule_snapshot_json['threshold_basis_cents'] ?? 0;

        return is_int($basis) ? $basis : 0;
    }

    private static function dollars(int $cents): string
    {
        return $cents < 0
            ? '-'.Money::centsToDollars(abs($cents))
            : Money::centsToDollars($cents);
    }
}
