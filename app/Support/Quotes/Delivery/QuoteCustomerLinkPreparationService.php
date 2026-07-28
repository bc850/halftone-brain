<?php

namespace App\Support\Quotes\Delivery;

use App\Enums\QuoteDeliveryChannel;
use App\Enums\QuoteDeliveryStatus;
use App\Enums\QuoteDocumentGenerationStatus;
use App\Enums\QuoteRevisionStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\Quote;
use App\Models\QuoteCustomerAccessToken;
use App\Models\QuoteDelivery;
use App\Models\QuoteDeliveryEvent;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionDocument;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Quotes\Documents\InvalidQuoteDocumentException;
use App\Support\Quotes\Security\QuoteCustomerAccessTokenGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prepares a one-time customer access link bound to the current generated document.
 *
 * Stores only the token hash. Returns the raw URL once to the caller.
 */
final class QuoteCustomerLinkPreparationService
{
    public function __construct(
        private QuoteCustomerAccessTokenGenerator $tokens,
        private Auditor $auditor,
    ) {}

    public function prepare(
        Quote $quote,
        QuoteRevision $revision,
        Membership $actorMembership,
        User $actor,
        ?string $recipientName = null,
        ?string $recipientEmail = null,
    ): QuoteCustomerLinkPreparationResult {
        $rawToken = $this->tokens->generateRaw();
        $tokenHash = $this->tokens->hashToken($rawToken);

        $result = DB::transaction(function () use (
            $quote,
            $revision,
            $actorMembership,
            $actor,
            $recipientName,
            $recipientEmail,
            $tokenHash,
        ): array {
            /** @var Quote $lockedQuote */
            $lockedQuote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            /** @var QuoteRevision $lockedRevision */
            $lockedRevision = QuoteRevision::query()
                ->whereKey($revision->id)
                ->where('quote_id', $lockedQuote->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertReadyForLink($lockedQuote, $lockedRevision);

            /** @var QuoteRevisionDocument $document */
            $document = QuoteRevisionDocument::query()
                ->whereKey($lockedRevision->current_document_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->generation_status !== QuoteDocumentGenerationStatus::Generated) {
                throw new InvalidQuoteDeliveryException('Customer link requires a generated document.');
            }

            $expiresAt = $lockedRevision->expiration_date?->endOfDay() ?? now()->addDays(14);
            $correlationId = (string) Str::uuid();

            $token = QuoteCustomerAccessToken::query()->create([
                'parent_account_id' => $lockedQuote->parent_account_id,
                'organization_id' => $lockedQuote->organization_id,
                'quote_id' => $lockedQuote->id,
                'quote_revision_id' => $lockedRevision->id,
                'quote_revision_document_id' => $document->id,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'created_by_membership_id' => $actorMembership->id,
                'created_by_user_id' => $actor->id,
            ]);

            $party = $lockedRevision->partySnapshot;
            $delivery = QuoteDelivery::query()->create([
                'parent_account_id' => $lockedQuote->parent_account_id,
                'organization_id' => $lockedQuote->organization_id,
                'quote_id' => $lockedQuote->id,
                'quote_revision_id' => $lockedRevision->id,
                'quote_revision_document_id' => $document->id,
                'channel' => QuoteDeliveryChannel::Manual,
                'recipient_name_snapshot' => $recipientName
                    ?? ($party !== null ? $party->contact_name : null)
                    ?? ($party !== null ? $party->customer_company_name : null)
                    ?? 'Customer',
                'recipient_email_snapshot' => $recipientEmail
                    ?? ($party !== null ? $party->contact_email : null)
                    ?? 'customer@example.invalid',
                'idempotency_key' => 'link-prep:'.$token->id.':'.$correlationId,
                'requested_by_membership_id' => $actorMembership->id,
                'requested_by_user_id' => $actor->id,
                'requested_at' => now(),
            ]);

            $delivery->forceFill([
                'status' => QuoteDeliveryStatus::Pending,
            ])->save();

            QuoteDeliveryEvent::query()->create([
                'parent_account_id' => $lockedQuote->parent_account_id,
                'organization_id' => $lockedQuote->organization_id,
                'quote_id' => $lockedQuote->id,
                'quote_revision_id' => $lockedRevision->id,
                'quote_delivery_id' => $delivery->id,
                'from_status' => null,
                'to_status' => QuoteDeliveryStatus::Pending,
                'metadata_json' => [
                    'purpose' => 'prepare_customer_link',
                    'token_id' => $token->id,
                    'document_id' => $document->id,
                ],
                'actor_membership_id' => $actorMembership->id,
                'actor_user_id' => $actor->id,
                'occurred_at' => now(),
                'correlation_id' => $correlationId,
            ]);

            $this->auditor->append(
                parentAccount: ParentAccount::query()->findOrFail($lockedQuote->parent_account_id),
                action: 'crm.quote.customer_link_prepared',
                subjectType: QuoteCustomerAccessToken::class,
                subjectId: $token->id,
                organization: Organization::query()->findOrFail($lockedQuote->organization_id),
                actor: $actor,
                after: [
                    'quote_revision_id' => $lockedRevision->id,
                    'document_id' => $document->id,
                    'delivery_id' => $delivery->id,
                    'token_id' => $token->id,
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
                correlationId: $correlationId,
            );

            return [
                'token_id' => $token->id,
                'delivery_id' => $delivery->id,
                'document_id' => $document->id,
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        });

        return new QuoteCustomerLinkPreparationResult(
            rawCustomerUrl: url('/customer/quotes/'.$rawToken),
            tokenId: $result['token_id'],
            deliveryId: $result['delivery_id'],
            documentId: $result['document_id'],
            expiresAt: $result['expires_at'],
        );
    }

    private function assertReadyForLink(Quote $quote, QuoteRevision $revision): void
    {
        if ($quote->current_revision_id !== $revision->id) {
            throw new InvalidQuoteDeliveryException('Only the current revision can prepare a customer link.');
        }

        if (! in_array($revision->status, [
            QuoteRevisionStatus::Approved,
            QuoteRevisionStatus::Sent,
            QuoteRevisionStatus::Viewed,
        ], true)) {
            throw new InvalidQuoteDeliveryException('Customer links require an approved, sent, or viewed revision.');
        }

        if (! $revision->tax_calculation_status->isResolved()) {
            throw new InvalidQuoteDeliveryException('Tax must be resolved before preparing a customer link.');
        }

        if ($revision->current_document_id === null) {
            throw new InvalidQuoteDocumentException('Generate a customer document before preparing a link.');
        }

        if ($revision->expiration_date === null || $revision->expiration_date->startOfDay()->lte(now()->startOfDay())) {
            throw new InvalidQuoteDeliveryException('A future expiration date is required for customer links.');
        }
    }
}
