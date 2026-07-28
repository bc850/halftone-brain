<?php

namespace App\Support\Quotes\Delivery;

use App\Enums\QuoteDeliveryChannel;
use App\Enums\QuoteDeliveryStatus;
use App\Enums\QuoteDocumentGenerationStatus;
use App\Enums\QuoteRevisionStatus;
use App\Enums\QuoteStatusTransitionSource;
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
use App\Support\Quotes\QuoteRevisionTransitionService;
use App\Support\Quotes\StaleQuoteStateException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records that a prepared customer link/document was sent outside the app (e.g. Outlook).
 */
final class QuoteManualDeliveryService
{
    public function __construct(
        private QuoteRevisionTransitionService $transitions,
        private Auditor $auditor,
    ) {}

    public function recordManualSend(
        Quote $quote,
        QuoteRevision $revision,
        QuoteDelivery $delivery,
        QuoteCustomerAccessToken $token,
        int $expectedQuoteLockVersion,
        int $expectedRevisionLockVersion,
        string $recipientName,
        string $recipientEmail,
        bool $confirmed,
        Membership $actorMembership,
        User $actor,
        ?string $externalReference = null,
    ): QuoteDelivery {
        if (! $confirmed) {
            throw new InvalidQuoteDeliveryException('Manual delivery requires explicit confirmation.');
        }

        if (trim($recipientName) === '' || trim($recipientEmail) === '') {
            throw new InvalidQuoteDeliveryException('Recipient name and email snapshots are required.');
        }

        return DB::transaction(function () use (
            $quote,
            $revision,
            $delivery,
            $token,
            $expectedQuoteLockVersion,
            $expectedRevisionLockVersion,
            $recipientName,
            $recipientEmail,
            $actorMembership,
            $actor,
            $externalReference,
        ): QuoteDelivery {
            /** @var Quote $lockedQuote */
            $lockedQuote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
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

            /** @var QuoteDelivery $lockedDelivery */
            $lockedDelivery = QuoteDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            /** @var QuoteCustomerAccessToken $lockedToken */
            $lockedToken = QuoteCustomerAccessToken::query()->whereKey($token->id)->lockForUpdate()->firstOrFail();
            /** @var QuoteRevisionDocument $document */
            $document = QuoteRevisionDocument::query()
                ->whereKey($lockedRevision->current_document_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCanRecord(
                $lockedQuote,
                $lockedRevision,
                $lockedDelivery,
                $lockedToken,
                $document,
            );

            $fromStatus = $lockedDelivery->status;
            $correlationId = (string) Str::uuid();
            $now = now();

            $lockedDelivery->forceFill([
                'channel' => QuoteDeliveryChannel::Manual,
                'status' => QuoteDeliveryStatus::ManuallyRecorded,
                'recipient_name_snapshot' => trim($recipientName),
                'recipient_email_snapshot' => trim($recipientEmail),
                'external_message_id' => $externalReference !== null && trim($externalReference) !== ''
                    ? trim($externalReference)
                    : $lockedDelivery->external_message_id,
                'sent_at' => $now,
                'failed_at' => null,
                'failure_code' => null,
                'failure_message' => null,
            ])->save();

            QuoteDeliveryEvent::query()->create([
                'parent_account_id' => $lockedQuote->parent_account_id,
                'organization_id' => $lockedQuote->organization_id,
                'quote_id' => $lockedQuote->id,
                'quote_revision_id' => $lockedRevision->id,
                'quote_delivery_id' => $lockedDelivery->id,
                'from_status' => $fromStatus,
                'to_status' => QuoteDeliveryStatus::ManuallyRecorded,
                'metadata_json' => [
                    'channel' => QuoteDeliveryChannel::Manual->value,
                    'token_id' => $lockedToken->id,
                    'document_id' => $document->id,
                    'has_external_reference' => $externalReference !== null && trim($externalReference) !== '',
                ],
                'actor_membership_id' => $actorMembership->id,
                'actor_user_id' => $actor->id,
                'occurred_at' => $now,
                'correlation_id' => $correlationId,
            ]);

            if ($lockedRevision->status === QuoteRevisionStatus::Approved) {
                $this->transitions->transition(
                    quote: $lockedQuote,
                    revision: $lockedRevision,
                    to: QuoteRevisionStatus::Sent,
                    source: QuoteStatusTransitionSource::User,
                    expectedQuoteLockVersion: $lockedQuote->lock_version,
                    expectedRevisionLockVersion: $lockedRevision->lock_version,
                    actor: $actor,
                    actorMembership: $actorMembership,
                    metadata: [
                        'delivery_id' => $lockedDelivery->id,
                        'delivery_status' => QuoteDeliveryStatus::ManuallyRecorded->value,
                    ],
                );
            }

            $this->auditor->append(
                parentAccount: ParentAccount::query()->findOrFail($lockedQuote->parent_account_id),
                action: 'crm.quote.manual_delivery_recorded',
                subjectType: QuoteDelivery::class,
                subjectId: $lockedDelivery->id,
                organization: Organization::query()->findOrFail($lockedQuote->organization_id),
                actor: $actor,
                after: [
                    'quote_revision_id' => $lockedRevision->id,
                    'document_id' => $document->id,
                    'token_id' => $lockedToken->id,
                    'delivery_status' => QuoteDeliveryStatus::ManuallyRecorded->value,
                ],
                correlationId: $correlationId,
            );

            return $lockedDelivery->fresh() ?? $lockedDelivery;
        });
    }

    private function assertCanRecord(
        Quote $quote,
        QuoteRevision $revision,
        QuoteDelivery $delivery,
        QuoteCustomerAccessToken $token,
        QuoteRevisionDocument $document,
    ): void {
        if ($quote->current_revision_id !== $revision->id) {
            throw new InvalidQuoteDeliveryException('Only the current revision can be marked manually sent.');
        }

        if ($revision->status !== QuoteRevisionStatus::Approved
            && $revision->status !== QuoteRevisionStatus::Sent
            && $revision->status !== QuoteRevisionStatus::Viewed) {
            throw new InvalidQuoteDeliveryException('Manual delivery requires an approved or already-sent revision.');
        }

        if (! $revision->tax_calculation_status->isResolved()) {
            throw new InvalidQuoteDeliveryException('Tax must be resolved before recording manual delivery.');
        }

        if ($document->generation_status !== QuoteDocumentGenerationStatus::Generated) {
            throw new InvalidQuoteDeliveryException('Manual delivery requires the current generated document.');
        }

        if ($delivery->quote_revision_document_id !== $document->id
            || $token->quote_revision_document_id !== $document->id) {
            throw new InvalidQuoteDeliveryException('Delivery and token must belong to the current document.');
        }

        if ($delivery->status !== QuoteDeliveryStatus::Pending) {
            throw new InvalidQuoteDeliveryException('Only pending deliveries can be recorded as manually sent.');
        }

        if (! $token->isUsable()) {
            throw new InvalidQuoteDeliveryException('Manual delivery requires a valid unexpired access token.');
        }
    }
}
