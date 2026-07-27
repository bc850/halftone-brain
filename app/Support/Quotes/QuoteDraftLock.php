<?php

namespace App\Support\Quotes;

use App\Enums\QuoteRevisionStatus;
use App\Models\Quote;
use App\Models\QuoteRevision;

/**
 * Shared pessimistic-lock + optimistic-version gate for every draft quote mutation.
 *
 * Callers must already be inside a database transaction.
 */
final class QuoteDraftLock
{
    /**
     * Lock the quote and revision for update and assert the revision is an
     * unmodified draft belonging to the quote.
     *
     * @return array{quote: Quote, revision: QuoteRevision}
     */
    public function lockDraft(Quote $quote, QuoteRevision $revision, int $expectedRevisionLockVersion): array
    {
        /** @var Quote $lockedQuote */
        $lockedQuote = Quote::query()
            ->whereKey($quote->id)
            ->lockForUpdate()
            ->firstOrFail();

        $lockedRevision = QuoteRevision::query()
            ->whereKey($revision->id)
            ->where('quote_id', $lockedQuote->id)
            ->lockForUpdate()
            ->first();

        if ($lockedRevision === null) {
            throw new InvalidQuoteDraftException('Revision does not belong to the given quote.');
        }

        if ($lockedRevision->status !== QuoteRevisionStatus::Draft) {
            throw new ImmutableQuoteRevisionException(
                "Quote revision [{$lockedRevision->id}] is {$lockedRevision->status->value} and can no longer be edited."
            );
        }

        if ($lockedRevision->lock_version !== $expectedRevisionLockVersion) {
            throw new StaleQuoteStateException;
        }

        return ['quote' => $lockedQuote, 'revision' => $lockedRevision];
    }

    /**
     * Draft revisions allow direct content writes, so no lifecycle escape hatch is needed here.
     */
    public function bumpRevisionLock(QuoteRevision $revision): void
    {
        $revision->forceFill(['lock_version' => $revision->lock_version + 1])->save();
    }
}
