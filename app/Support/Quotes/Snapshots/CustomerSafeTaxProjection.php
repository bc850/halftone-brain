<?php

namespace App\Support\Quotes\Snapshots;

use App\Support\Quotes\Tax\QuoteTaxCalculationResult;

/**
 * Customer-safe view of a tax outcome.
 *
 * A customer sees the tax amount only once the position is resolved. While tax
 * needs review they see that it is not final, never the reasons: those name
 * certificate problems and internal process, which are ours to fix, not theirs
 * to read. Certificate numbers, internal notes, rejection reasons, override
 * reasons, and actor identities are omitted entirely.
 *
 * @phpstan-type CustomerSafeTax array{
 *     tax_status: string,
 *     tax_resolved: bool,
 *     tax_cents: int|null,
 *     taxable_basis_cents: int|null,
 *     jurisdiction_display_name: string|null
 * }
 */
final class CustomerSafeTaxProjection
{
    /**
     * @return CustomerSafeTax
     */
    public function fromResult(QuoteTaxCalculationResult $result): array
    {
        $resolved = $result->isResolved();
        $displayName = $result->jurisdictionSnapshot['display_name'] ?? null;

        return [
            'tax_status' => $result->revisionStatus()->value,
            'tax_resolved' => $resolved,
            'tax_cents' => $resolved ? $result->taxCents : null,
            'taxable_basis_cents' => $resolved ? $result->taxableBasisCents : null,
            'jurisdiction_display_name' => is_string($displayName) ? $displayName : null,
        ];
    }

    /**
     * Keys that must never appear in a customer-facing tax payload.
     *
     * @return list<string>
     */
    public static function forbiddenKeys(): array
    {
        return [
            'certificate_number',
            'certificate_evidence_snapshot',
            'certificate_evidence_snapshot_json',
            'internal_notes',
            'rejection_reason',
            'override_reason',
            'is_override',
            'review_reasons',
            'actor_membership_id',
            'actor_user_id',
            'approver_membership_id',
            'approver_user_id',
            'requested_by_membership_id',
            'requested_by_user_id',
            'rule_snapshot',
            'rule_snapshot_json',
        ];
    }
}
