<?php

namespace App\Support\Quotes;

use App\Enums\MembershipStatus;
use App\Enums\QuoteLifecycleStatus;
use App\Enums\QuoteRevisionStatus;
use App\Enums\QuoteStatusTransitionSource;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationCompany;
use App\Models\ParentAccount;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteStatusEvent;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Deals\DealQuoteStageSynchronizer;
use App\Support\Pricing\PricingCalculator;
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
        private QuotePartySnapshotBuilder $partySnapshots,
    ) {}

    public function create(
        Deal $deal,
        Membership $createdByMembership,
        Organization $organization,
        string $quotePrefix,
        int $padLength = 5,
        ?Membership $salesOwnerMembership = null,
        ?User $actor = null,
        ?Contact $primaryContact = null,
        ?string $expirationDate = null,
        ?string $customerPoReference = null,
        ?string $introduction = null,
        ?string $termsText = null,
        ?string $customerNotes = null,
        ?string $internalNotes = null,
    ): Quote {
        if ($deal->organization_id !== $organization->id) {
            throw new InvalidArgumentException('Deal organization does not match quote organization.');
        }

        $this->assertMembership($createdByMembership, $organization, 'Created-by');

        if ($salesOwnerMembership !== null) {
            $this->assertMembership($salesOwnerMembership, $organization, 'Sales-owner');
        }

        if ($deal->organization_company_id === null) {
            throw new InvalidArgumentException('Deal must have an organization company before creating a quote.');
        }

        $orgCompany = OrganizationCompany::query()->findOrFail($deal->organization_company_id);
        $company = Company::query()->findOrFail($orgCompany->company_id);

        if ($orgCompany->organization_id !== $organization->id) {
            throw new InvalidArgumentException('Deal organization company does not belong to the quote organization.');
        }

        if ($orgCompany->parent_account_id !== $organization->parent_account_id
            || $company->parent_account_id !== $organization->parent_account_id) {
            throw new InvalidArgumentException('Customer company must belong to the quote parent account.');
        }

        if ($primaryContact !== null) {
            if ($primaryContact->company_id !== $company->id) {
                throw new InvalidArgumentException('Primary contact must belong to the customer company.');
            }

            if ($primaryContact->parent_account_id !== $company->parent_account_id) {
                throw new InvalidArgumentException('Primary contact must belong to the customer parent account.');
            }
        }

        // Allocated outside the create transaction on purpose: a failed create must burn the
        // number and leave a gap rather than hand the same number to the next quote.
        $quoteNumber = $this->sequences->allocate(
            $organization,
            NumberSequenceAllocator::KEY_QUOTE,
            $quotePrefix,
            $padLength,
        );

        return DB::transaction(function () use (
            $deal,
            $createdByMembership,
            $organization,
            $quoteNumber,
            $salesOwnerMembership,
            $actor,
            $primaryContact,
            $expirationDate,
            $customerPoReference,
            $introduction,
            $termsText,
            $customerNotes,
            $internalNotes,
        ): Quote {
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
                    'currency_code' => PricingCalculator::CURRENCY_USD,
                    'expiration_date' => $expirationDate,
                    'introduction' => $introduction,
                    'terms_text' => $termsText,
                    'customer_notes' => $customerNotes,
                    'internal_notes' => $internalNotes,
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

            $this->partySnapshots->createInitial(
                quote: $quote,
                revision: $revision,
                preparer: $createdByMembership,
                primaryContact: $primaryContact,
                salesperson: $salesOwnerMembership,
                customerPoReference: $customerPoReference,
            );

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

    private function assertMembership(Membership $membership, Organization $organization, string $label): void
    {
        if ($membership->organization_id !== $organization->id) {
            throw new InvalidArgumentException("{$label} membership must belong to the quote organization.");
        }

        if ($membership->status !== MembershipStatus::Active) {
            throw new InvalidArgumentException("{$label} membership must be active.");
        }
    }
}
