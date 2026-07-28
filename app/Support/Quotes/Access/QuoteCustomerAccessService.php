<?php

namespace App\Support\Quotes\Access;

use App\Enums\QuoteDocumentGenerationStatus;
use App\Enums\QuoteRevisionStatus;
use App\Enums\QuoteStatusTransitionSource;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\Quote;
use App\Models\QuoteCustomerAccessToken;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionDocument;
use App\Support\Audit\Auditor;
use App\Support\Quotes\Documents\InvalidQuoteDocumentException;
use App\Support\Quotes\QuoteRevisionTransitionService;
use App\Support\Quotes\Security\QuoteCustomerAccessTokenGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resolves customer access tokens and records first-view Sent → Viewed transitions.
 */
final class QuoteCustomerAccessService
{
    public function __construct(
        private QuoteCustomerAccessTokenGenerator $tokens,
        private QuoteRevisionTransitionService $transitions,
        private Auditor $auditor,
    ) {}

    /**
     * Constant-time token resolution: hash then verify with hash_equals.
     * Returns null for invalid, expired, or revoked tokens without leaking existence.
     */
    public function resolveUsableToken(string $rawToken): ?QuoteCustomerAccessToken
    {
        if ($rawToken === '') {
            return null;
        }

        $hash = $this->tokens->hashToken($rawToken);

        /** @var QuoteCustomerAccessToken|null $token */
        $token = QuoteCustomerAccessToken::query()
            ->where('token_hash', $hash)
            ->first();

        if ($token === null) {
            return null;
        }

        if (! $this->tokens->verify($rawToken, $token->token_hash)) {
            return null;
        }

        if (! $token->isUsable()) {
            return null;
        }

        return $token;
    }

    /**
     * @return array{
     *     token: QuoteCustomerAccessToken,
     *     document: QuoteRevisionDocument,
     *     quote: Quote,
     *     revision: QuoteRevision
     * }
     */
    public function open(string $rawToken): array
    {
        $token = $this->resolveUsableToken($rawToken);
        if ($token === null) {
            throw new InvalidQuoteDocumentException('Customer quote access is unavailable.');
        }

        return DB::transaction(function () use ($token): array {
            /** @var QuoteCustomerAccessToken $lockedToken */
            $lockedToken = QuoteCustomerAccessToken::query()->whereKey($token->id)->lockForUpdate()->firstOrFail();

            if (! $lockedToken->isUsable()) {
                throw new InvalidQuoteDocumentException('Customer quote access is unavailable.');
            }

            /** @var Quote $quote */
            $quote = Quote::query()->whereKey($lockedToken->quote_id)->lockForUpdate()->firstOrFail();
            /** @var QuoteRevision $revision */
            $revision = QuoteRevision::query()->whereKey($lockedToken->quote_revision_id)->lockForUpdate()->firstOrFail();
            /** @var QuoteRevisionDocument $document */
            $document = QuoteRevisionDocument::query()
                ->whereKey($lockedToken->quote_revision_document_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->generation_status !== QuoteDocumentGenerationStatus::Generated) {
                throw new InvalidQuoteDocumentException('Customer quote access is unavailable.');
            }

            if (! in_array($revision->status, [
                QuoteRevisionStatus::Sent,
                QuoteRevisionStatus::Viewed,
                QuoteRevisionStatus::Accepted,
                QuoteRevisionStatus::Rejected,
            ], true)) {
                throw new InvalidQuoteDocumentException('Customer quote access is unavailable.');
            }

            $wasFirstView = $lockedToken->view_count === 0;
            $lockedToken->forceFill([
                'view_count' => $lockedToken->view_count + 1,
                'last_viewed_at' => now(),
            ])->save();

            if ($wasFirstView && $revision->status === QuoteRevisionStatus::Sent) {
                $this->transitions->transition(
                    quote: $quote,
                    revision: $revision,
                    to: QuoteRevisionStatus::Viewed,
                    source: QuoteStatusTransitionSource::Customer,
                    expectedQuoteLockVersion: $quote->lock_version,
                    expectedRevisionLockVersion: $revision->lock_version,
                    metadata: [
                        'token_id' => $lockedToken->id,
                        'document_id' => $document->id,
                    ],
                );

                $this->auditor->append(
                    parentAccount: ParentAccount::query()->findOrFail($quote->parent_account_id),
                    action: 'crm.quote.customer_first_viewed',
                    subjectType: QuoteRevision::class,
                    subjectId: $revision->id,
                    organization: Organization::query()->findOrFail($quote->organization_id),
                    after: [
                        'document_id' => $document->id,
                        'token_id' => $lockedToken->id,
                    ],
                    correlationId: (string) Str::uuid(),
                );
            }

            return [
                'token' => $lockedToken->fresh() ?? $lockedToken,
                'document' => $document->fresh() ?? $document,
                'quote' => $quote->fresh() ?? $quote,
                'revision' => $revision->fresh() ?? $revision,
            ];
        });
    }
}
