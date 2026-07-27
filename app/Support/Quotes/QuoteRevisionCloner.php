<?php

namespace App\Support\Quotes;

use App\Enums\QuoteRevisionStatus;
use App\Enums\QuoteStatusTransitionSource;
use App\Enums\QuoteTaxCalculationStatus;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteStatusEvent;
use App\Models\User;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Clone an eligible revision into a new draft, including its party snapshot, lines,
 * and adjustments. `$afterCreate` runs after children are copied for extra hooks.
 */
final class QuoteRevisionCloner
{
    public function __construct(
        private Auditor $auditor,
        private QuoteRevisionChildrenCloner $children,
    ) {}

    /**
     * @param  callable(QuoteRevision $newRevision, QuoteRevision $source): void|null  $afterCreate
     */
    public function cloneToDraft(
        Quote $quote,
        QuoteRevision $source,
        int $expectedQuoteLockVersion,
        ?User $actor = null,
        ?callable $afterCreate = null,
    ): QuoteRevision {
        if ($source->quote_id !== $quote->id) {
            throw new InvalidArgumentException('Source revision does not belong to the given quote.');
        }

        if (! in_array($source->status, [
            QuoteRevisionStatus::Draft,
            QuoteRevisionStatus::PendingApproval,
            QuoteRevisionStatus::Approved,
            QuoteRevisionStatus::Sent,
            QuoteRevisionStatus::Viewed,
            QuoteRevisionStatus::Rejected,
            QuoteRevisionStatus::Expired,
        ], true)) {
            throw new InvalidArgumentException(
                "Cannot clone quote revision in status [{$source->status->value}]."
            );
        }

        return DB::transaction(function () use (
            $quote,
            $source,
            $expectedQuoteLockVersion,
            $actor,
            $afterCreate,
        ): QuoteRevision {
            /** @var Quote $lockedQuote */
            $lockedQuote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ($lockedQuote->lock_version !== $expectedQuoteLockVersion) {
                throw new StaleQuoteStateException;
            }

            /** @var QuoteRevision $lockedSource */
            $lockedSource = QuoteRevision::query()
                ->whereKey($source->id)
                ->where('quote_id', $lockedQuote->id)
                ->lockForUpdate()
                ->firstOrFail();

            $nextNumber = (int) QuoteRevision::query()
                ->where('quote_id', $lockedQuote->id)
                ->lockForUpdate()
                ->max('revision_number') + 1;

            QuoteRevision::$allowLifecycleMutation = true;
            Quote::$allowLifecycleMutation = true;

            try {
                $newRevision = QuoteRevision::query()->create([
                    'parent_account_id' => $lockedQuote->parent_account_id,
                    'organization_id' => $lockedQuote->organization_id,
                    'quote_id' => $lockedQuote->id,
                    'revision_number' => $nextNumber,
                    'source_revision_id' => $lockedSource->id,
                    'status' => QuoteRevisionStatus::Draft,
                    'lock_version' => 1,
                    'currency_code' => $lockedSource->currency_code,
                    'issue_date' => null,
                    'expiration_date' => $lockedSource->expiration_date,
                    'introduction' => $lockedSource->introduction,
                    'customer_notes' => $lockedSource->customer_notes,
                    'terms_text' => $lockedSource->terms_text,
                    'internal_notes' => $lockedSource->internal_notes,
                    'subtotal_cents' => $lockedSource->subtotal_cents,
                    'discount_cents' => $lockedSource->discount_cents,
                    'taxable_amount_cents' => $lockedSource->taxable_amount_cents,
                    'tax_cents' => $lockedSource->tax_cents,
                    'grand_total_cents' => $lockedSource->grand_total_cents,
                    // Tax is unresolved on every new draft; the source snapshot never carries over.
                    'tax_calculation_status' => QuoteTaxCalculationStatus::Pending,
                    'tax_snapshot_json' => null,
                    'tax_calculated_at' => null,
                    'requested_deposit_cents' => $lockedSource->requested_deposit_cents,
                    'approval_required' => false,
                    'approval_reason_snapshot' => null,
                    'pricing_snapshotted_at' => $lockedSource->pricing_snapshotted_at,
                    'sent_at' => null,
                    'viewed_at' => null,
                    'accepted_at' => null,
                    'rejected_at' => null,
                    'expired_at' => null,
                    'superseded_at' => null,
                    'voided_at' => null,
                ]);

                $lockedQuote->forceFill([
                    'current_revision_id' => $newRevision->id,
                    'lock_version' => $lockedQuote->lock_version + 1,
                ])->save();
            } finally {
                QuoteRevision::$allowLifecycleMutation = false;
                Quote::$allowLifecycleMutation = false;
            }

            $this->children->copy($lockedSource, $newRevision);

            if ($afterCreate !== null) {
                $afterCreate($newRevision, $lockedSource);
            }

            $correlationId = (string) Str::uuid();

            QuoteStatusEvent::query()->create([
                'parent_account_id' => $lockedQuote->parent_account_id,
                'organization_id' => $lockedQuote->organization_id,
                'quote_id' => $lockedQuote->id,
                'quote_revision_id' => $newRevision->id,
                'from_status' => null,
                'to_status' => QuoteRevisionStatus::Draft->value,
                'actor_user_id' => $actor?->id,
                'actor_membership_id' => null,
                'transition_source' => QuoteStatusTransitionSource::Clone,
                'metadata_json' => [
                    'source_revision_id' => $lockedSource->id,
                    'source_revision_number' => $lockedSource->revision_number,
                ],
                'occurred_at' => now(),
                'correlation_id' => $correlationId,
            ]);

            $this->auditor->append(
                parentAccount: ParentAccount::query()->findOrFail($lockedQuote->parent_account_id),
                action: 'crm.quote.revision_cloned',
                subjectType: QuoteRevision::class,
                subjectId: $newRevision->id,
                organization: Organization::query()->findOrFail($lockedQuote->organization_id),
                actor: $actor,
                after: [
                    'revision_number' => $newRevision->revision_number,
                    'source_revision_id' => $lockedSource->id,
                ],
                correlationId: $correlationId,
            );

            return $newRevision->fresh() ?? $newRevision;
        });
    }
}
