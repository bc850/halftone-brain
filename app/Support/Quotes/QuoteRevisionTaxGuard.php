<?php

namespace App\Support\Quotes;

use App\Models\QuoteRevision;

/**
 * Guards tax and approval records attached to a quote revision.
 *
 * Once a revision reaches a customer-visible status its financial content is
 * frozen, so no new tax calculation or approval request may be attached to it by
 * ordinary code paths. A controlled workflow — for example an approved
 * re-calculation that deliberately supersedes history — opts in through
 * `allowingControlledWorkflow()` rather than bypassing the model guards.
 */
final class QuoteRevisionTaxGuard
{
    private static bool $controlledWorkflow = false;

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function allowingControlledWorkflow(callable $callback): mixed
    {
        $previous = self::$controlledWorkflow;
        self::$controlledWorkflow = true;

        try {
            return $callback();
        } finally {
            self::$controlledWorkflow = $previous;
        }
    }

    public static function inControlledWorkflow(): bool
    {
        return self::$controlledWorkflow;
    }

    /**
     * @throws ImmutableQuoteRevisionException
     */
    public static function assertMayAttachTo(?int $quoteRevisionId, string $subject): void
    {
        if ($quoteRevisionId === null || self::$controlledWorkflow) {
            return;
        }

        $revision = QuoteRevision::query()->find($quoteRevisionId);

        if ($revision === null) {
            return;
        }

        self::assertRevisionAcceptsFinancialWork($revision, $subject);
    }

    /**
     * @throws ImmutableQuoteRevisionException
     */
    public static function assertMayMutateFinancialContent(QuoteRevision $revision): void
    {
        if (self::$controlledWorkflow) {
            return;
        }

        self::assertRevisionAcceptsFinancialWork($revision, 'Financial content');
    }

    private static function assertRevisionAcceptsFinancialWork(QuoteRevision $revision, string $subject): void
    {
        if (! $revision->status->isCustomerContentImmutable()) {
            return;
        }

        throw new ImmutableQuoteRevisionException(sprintf(
            '%s cannot be recorded while quote revision [%d] is %s.',
            $subject,
            $revision->id,
            $revision->status->value,
        ));
    }
}
