<?php

namespace App\Support\Quotes\Token;

use App\Enums\QuoteDocumentGenerationStatus;
use App\Enums\QuoteRevisionStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\Quote;
use App\Models\QuoteCustomerAccessToken;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionDocument;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Quotes\Delivery\InvalidQuoteDeliveryException;
use App\Support\Quotes\Delivery\QuoteCustomerLinkPreparationResult;
use App\Support\Quotes\Delivery\QuoteCustomerLinkPreparationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Revoke and regenerate customer access tokens. Never persists raw token values.
 */
final class QuoteCustomerTokenLifecycleService
{
    public function __construct(
        private QuoteCustomerLinkPreparationService $linkPreparation,
        private Auditor $auditor,
    ) {}

    public function revoke(
        QuoteCustomerAccessToken $token,
        string $reason,
        ?User $actor = null,
        ?Membership $actorMembership = null,
    ): QuoteCustomerAccessToken {
        return DB::transaction(function () use ($token, $reason, $actor, $actorMembership): QuoteCustomerAccessToken {
            /** @var QuoteCustomerAccessToken $locked */
            $locked = QuoteCustomerAccessToken::query()->whereKey($token->id)->lockForUpdate()->firstOrFail();

            if ($locked->isRevoked()) {
                return $locked;
            }

            $locked->forceFill([
                'revoked_at' => now(),
                'revoke_reason' => trim($reason) !== '' ? trim($reason) : 'revoked',
            ])->save();

            $this->auditor->append(
                parentAccount: ParentAccount::query()->findOrFail($locked->parent_account_id),
                action: 'crm.quote.customer_token_revoked',
                subjectType: QuoteCustomerAccessToken::class,
                subjectId: $locked->id,
                organization: Organization::query()->findOrFail($locked->organization_id),
                actor: $actor,
                after: [
                    'quote_revision_id' => $locked->quote_revision_id,
                    'token_id' => $locked->id,
                    'revoke_reason' => $locked->revoke_reason,
                    'actor_membership_id' => $actorMembership?->id,
                ],
                correlationId: (string) Str::uuid(),
            );

            return $locked->fresh() ?? $locked;
        });
    }

    public function revokeActiveTokensForRevision(
        QuoteRevision $revision,
        string $reason,
        ?User $actor = null,
        ?int $exceptTokenId = null,
    ): void {
        $tokens = QuoteCustomerAccessToken::query()
            ->where('quote_revision_id', $revision->id)
            ->whereNull('revoked_at')
            ->when($exceptTokenId !== null, fn ($query) => $query->whereKeyNot($exceptTokenId))
            ->lockForUpdate()
            ->get();

        foreach ($tokens as $token) {
            $this->revoke($token, $reason, $actor);
        }
    }

    public function regenerate(
        Quote $quote,
        QuoteRevision $revision,
        Membership $actorMembership,
        User $actor,
        ?string $recipientName = null,
        ?string $recipientEmail = null,
    ): QuoteCustomerLinkPreparationResult {
        return DB::transaction(function () use (
            $quote,
            $revision,
            $actorMembership,
            $actor,
            $recipientName,
            $recipientEmail,
        ): QuoteCustomerLinkPreparationResult {
            /** @var QuoteRevision $lockedRevision */
            $lockedRevision = QuoteRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();

            if ($lockedRevision->status->isTerminal()) {
                throw new InvalidQuoteDeliveryException('Cannot regenerate tokens for a terminal revision.');
            }

            if ($lockedRevision->current_document_id === null) {
                throw new InvalidQuoteDeliveryException('Cannot regenerate tokens without a current generated document.');
            }

            /** @var QuoteRevisionDocument $document */
            $document = QuoteRevisionDocument::query()->whereKey($lockedRevision->current_document_id)->firstOrFail();
            if ($document->generation_status !== QuoteDocumentGenerationStatus::Generated) {
                throw new InvalidQuoteDeliveryException('Cannot regenerate tokens without a generated document.');
            }

            if (! in_array($lockedRevision->status, [
                QuoteRevisionStatus::Approved,
                QuoteRevisionStatus::Sent,
                QuoteRevisionStatus::Viewed,
            ], true)) {
                throw new InvalidQuoteDeliveryException('Token regeneration requires an approved, sent, or viewed revision.');
            }

            $this->revokeActiveTokensForRevision($lockedRevision, 'regenerated', $actor);

            $prepared = $this->linkPreparation->prepare(
                quote: $quote->fresh() ?? $quote,
                revision: $lockedRevision->fresh() ?? $lockedRevision,
                actorMembership: $actorMembership,
                actor: $actor,
                recipientName: $recipientName,
                recipientEmail: $recipientEmail,
            );

            $this->auditor->append(
                parentAccount: ParentAccount::query()->findOrFail($quote->parent_account_id),
                action: 'crm.quote.customer_token_regenerated',
                subjectType: QuoteCustomerAccessToken::class,
                subjectId: $prepared->tokenId,
                organization: Organization::query()->findOrFail($quote->organization_id),
                actor: $actor,
                after: [
                    'quote_revision_id' => $revision->id,
                    'token_id' => $prepared->tokenId,
                    'delivery_id' => $prepared->deliveryId,
                    'document_id' => $prepared->documentId,
                ],
                correlationId: (string) Str::uuid(),
            );

            return $prepared;
        });
    }
}
