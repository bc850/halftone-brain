<?php

namespace App\Support\Quotes;

use App\Enums\QuoteLifecycleStatus;
use App\Enums\QuoteRevisionStatus;
use App\Enums\QuoteStatusTransitionSource;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteStatusEvent;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Deals\DealQuoteStageSynchronizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Transactional quote revision status transitions with locking, events, and audits.
 */
final class QuoteRevisionTransitionService
{
    public function __construct(
        private Auditor $auditor,
        private DealQuoteStageSynchronizer $dealSync,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function transition(
        Quote $quote,
        QuoteRevision $revision,
        QuoteRevisionStatus $to,
        QuoteStatusTransitionSource $source,
        int $expectedQuoteLockVersion,
        int $expectedRevisionLockVersion,
        ?User $actor = null,
        ?Membership $actorMembership = null,
        array $metadata = [],
    ): QuoteRevision {
        if ($revision->quote_id !== $quote->id) {
            throw new InvalidArgumentException('Revision does not belong to the given quote.');
        }

        return DB::transaction(function () use (
            $quote,
            $revision,
            $to,
            $source,
            $expectedQuoteLockVersion,
            $expectedRevisionLockVersion,
            $actor,
            $actorMembership,
            $metadata,
        ): QuoteRevision {
            /** @var Quote $lockedQuote */
            $lockedQuote = Quote::query()
                ->whereKey($quote->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var QuoteRevision $lockedRevision */
            $lockedRevision = QuoteRevision::query()
                ->whereKey($revision->id)
                ->where('quote_id', $lockedQuote->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedQuote->lock_version !== $expectedQuoteLockVersion
                || $lockedRevision->lock_version !== $expectedRevisionLockVersion) {
                throw new StaleQuoteStateException;
            }

            $from = $lockedRevision->status;

            if ($from === $to) {
                if ($to->isTerminal()) {
                    return $lockedRevision;
                }

                throw new IllegalQuoteTransitionException(
                    "Quote revision is already [{$to->value}] and the transition is not a terminal no-op."
                );
            }

            try {
                QuoteRevisionStateMachine::assertCanTransition($from, $to);
            } catch (InvalidArgumentException $exception) {
                throw new IllegalQuoteTransitionException($exception->getMessage(), 0, $exception);
            }

            if ($to === QuoteRevisionStatus::Accepted && $lockedQuote->accepted_revision_id !== null) {
                throw new IllegalQuoteTransitionException(
                    'Quote already has an accepted revision.'
                );
            }

            $correlationId = (string) Str::uuid();
            $now = now();

            $lifecycleAttributes = [
                'status' => $to,
                'lock_version' => $lockedRevision->lock_version + 1,
            ];

            $timestampAttribute = match ($to) {
                QuoteRevisionStatus::Sent => 'sent_at',
                QuoteRevisionStatus::Viewed => 'viewed_at',
                QuoteRevisionStatus::Accepted => 'accepted_at',
                QuoteRevisionStatus::Rejected => 'rejected_at',
                QuoteRevisionStatus::Expired => 'expired_at',
                QuoteRevisionStatus::Superseded => 'superseded_at',
                QuoteRevisionStatus::Void => 'voided_at',
                default => null,
            };

            if ($timestampAttribute !== null) {
                $lifecycleAttributes[$timestampAttribute] = $now;
            }

            QuoteRevision::$allowLifecycleMutation = true;
            Quote::$allowLifecycleMutation = true;

            try {
                $lockedRevision->forceFill($lifecycleAttributes)->save();

                $quoteAttributes = [
                    'lock_version' => $lockedQuote->lock_version + 1,
                    'lifecycle_status' => $this->deriveQuoteLifecycle($lockedQuote, $lockedRevision, $to),
                ];

                if ($to === QuoteRevisionStatus::Accepted) {
                    $quoteAttributes['accepted_revision_id'] = $lockedRevision->id;
                    $quoteAttributes['current_revision_id'] = $lockedRevision->id;
                }

                if (in_array($to, [
                    QuoteRevisionStatus::Draft,
                    QuoteRevisionStatus::PendingApproval,
                    QuoteRevisionStatus::Approved,
                    QuoteRevisionStatus::Sent,
                    QuoteRevisionStatus::Viewed,
                ], true)) {
                    $quoteAttributes['current_revision_id'] = $lockedRevision->id;
                }

                $lockedQuote->forceFill($quoteAttributes)->save();
            } finally {
                QuoteRevision::$allowLifecycleMutation = false;
                Quote::$allowLifecycleMutation = false;
            }

            QuoteStatusEvent::query()->create([
                'parent_account_id' => $lockedQuote->parent_account_id,
                'organization_id' => $lockedQuote->organization_id,
                'quote_id' => $lockedQuote->id,
                'quote_revision_id' => $lockedRevision->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_user_id' => $actor?->id,
                'actor_membership_id' => $actorMembership?->id,
                'transition_source' => $source,
                'metadata_json' => $metadata === [] ? null : $metadata,
                'occurred_at' => $now,
                'correlation_id' => $correlationId,
            ]);

            $parent = ParentAccount::query()->findOrFail($lockedQuote->parent_account_id);
            $organization = Organization::query()->findOrFail($lockedQuote->organization_id);

            $this->auditor->append(
                parentAccount: $parent,
                action: 'crm.quote.revision_status_changed',
                subjectType: QuoteRevision::class,
                subjectId: $lockedRevision->id,
                organization: $organization,
                actor: $actor,
                before: ['status' => $from->value, 'lock_version' => $expectedRevisionLockVersion],
                after: ['status' => $to->value, 'lock_version' => $lockedRevision->lock_version, 'source' => $source->value],
                correlationId: $correlationId,
            );

            $this->dealSync->onRevisionTransitioned(
                $lockedQuote->fresh() ?? $lockedQuote,
                $lockedRevision->fresh() ?? $lockedRevision,
                $to,
                $actor,
            );

            return $lockedRevision->fresh() ?? $lockedRevision;
        });
    }

    private function deriveQuoteLifecycle(
        Quote $quote,
        QuoteRevision $revision,
        QuoteRevisionStatus $to,
    ): QuoteLifecycleStatus {
        return match ($to) {
            QuoteRevisionStatus::Accepted => QuoteLifecycleStatus::Accepted,
            QuoteRevisionStatus::Rejected => QuoteLifecycleStatus::Rejected,
            QuoteRevisionStatus::Expired => QuoteLifecycleStatus::Expired,
            QuoteRevisionStatus::Void => $quote->accepted_revision_id !== null
                ? QuoteLifecycleStatus::Accepted
                : QuoteLifecycleStatus::Void,
            default => QuoteLifecycleStatus::Open,
        };
    }
}
