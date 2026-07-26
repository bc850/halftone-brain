<?php

namespace App\Support\Quotes;

use App\Enums\QuoteLifecycleStatus;
use App\Enums\QuoteRevisionStatus;
use App\Enums\QuoteStatusTransitionSource;
use App\Models\Deal;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteStatusEvent;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Deals\DealQuoteStageSynchronizer;
use App\Support\Tenancy\NumberSequenceAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates a Quote with revision 1 (draft) and assigns an org quote number.
 */
final class QuoteFactoryService
{
    public function __construct(
        private NumberSequenceAllocator $sequences,
        private Auditor $auditor,
        private DealQuoteStageSynchronizer $dealSync,
    ) {}

    public function create(
        Deal $deal,
        Membership $createdByMembership,
        Organization $organization,
        string $quotePrefix,
        int $padLength = 5,
        ?Membership $salesOwnerMembership = null,
        ?User $actor = null,
    ): Quote {
        if ($deal->organization_id !== $organization->id) {
            throw new InvalidArgumentException('Deal organization does not match quote organization.');
        }

        if ($createdByMembership->organization_id !== $organization->id) {
            throw new InvalidArgumentException('Created-by membership must belong to the quote organization.');
        }

        if ($salesOwnerMembership !== null && $salesOwnerMembership->organization_id !== $organization->id) {
            throw new InvalidArgumentException('Sales-owner membership must belong to the quote organization.');
        }

        if ($deal->organization_company_id === null) {
            throw new InvalidArgumentException('Deal must have an organization company before creating a quote.');
        }

        return DB::transaction(function () use (
            $deal,
            $createdByMembership,
            $organization,
            $quotePrefix,
            $padLength,
            $salesOwnerMembership,
            $actor,
        ): Quote {
            $quoteNumber = $this->sequences->allocate(
                $organization,
                NumberSequenceAllocator::KEY_QUOTE,
                $quotePrefix,
                $padLength,
            );

            $parent = ParentAccount::query()->findOrFail($organization->parent_account_id);
            $correlationId = (string) Str::uuid();

            Quote::$allowLifecycleMutation = true;
            QuoteRevision::$allowLifecycleMutation = true;

            try {
                $quote = Quote::query()->create([
                    'parent_account_id' => $organization->parent_account_id,
                    'organization_id' => $organization->id,
                    'deal_id' => $deal->id,
                    'organization_company_id' => $deal->organization_company_id,
                    'quote_number' => $quoteNumber,
                    'lifecycle_status' => QuoteLifecycleStatus::Open,
                    'current_revision_id' => null,
                    'accepted_revision_id' => null,
                    'created_by_membership_id' => $createdByMembership->id,
                    'sales_owner_membership_id' => $salesOwnerMembership?->id,
                    'lock_version' => 1,
                ]);

                $revision = QuoteRevision::query()->create([
                    'parent_account_id' => $organization->parent_account_id,
                    'organization_id' => $organization->id,
                    'quote_id' => $quote->id,
                    'revision_number' => 1,
                    'source_revision_id' => null,
                    'status' => QuoteRevisionStatus::Draft,
                    'lock_version' => 1,
                    'currency_code' => 'USD',
                    'subtotal_cents' => 0,
                    'discount_cents' => 0,
                    'taxable_amount_cents' => 0,
                    'tax_cents' => 0,
                    'grand_total_cents' => 0,
                    'approval_required' => false,
                ]);

                $quote->forceFill([
                    'current_revision_id' => $revision->id,
                ])->save();
            } finally {
                Quote::$allowLifecycleMutation = false;
                QuoteRevision::$allowLifecycleMutation = false;
            }

            QuoteStatusEvent::query()->create([
                'parent_account_id' => $organization->parent_account_id,
                'organization_id' => $organization->id,
                'quote_id' => $quote->id,
                'quote_revision_id' => $revision->id,
                'from_status' => null,
                'to_status' => QuoteRevisionStatus::Draft->value,
                'actor_user_id' => $actor?->id,
                'actor_membership_id' => $createdByMembership->id,
                'transition_source' => QuoteStatusTransitionSource::System,
                'metadata_json' => ['event' => 'quote_created'],
                'occurred_at' => now(),
                'correlation_id' => $correlationId,
            ]);

            $this->auditor->append(
                parentAccount: $parent,
                action: 'crm.quote.created',
                subjectType: Quote::class,
                subjectId: $quote->id,
                organization: $organization,
                actor: $actor,
                after: [
                    'quote_number' => $quote->quote_number,
                    'deal_id' => $deal->id,
                    'revision_id' => $revision->id,
                ],
                correlationId: $correlationId,
            );

            $this->dealSync->onQuoteCreated($quote->fresh(), $actor);

            return $quote->fresh(['currentRevision', 'revisions']) ?? $quote;
        });
    }
}
