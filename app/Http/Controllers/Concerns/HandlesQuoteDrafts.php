<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Deal;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Support\Quotes\Approval\InvalidQuoteApprovalException;
use App\Support\Quotes\ImmutableQuoteRevisionException;
use App\Support\Quotes\InvalidQuoteDraftException;
use App\Support\Quotes\Tax\InvalidQuoteTaxCalculationException;
use App\Support\Quotes\Totals\InvalidQuoteTotalsException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Shared nesting, authority, and domain-error translation for the quote draft builder.
 */
trait HandlesQuoteDrafts
{
    /**
     * Price override authority for custom lines, unit price overrides, and quote discounts.
     */
    protected function mayOverridePrice(): bool
    {
        return TenantContext::has()
            && TenantContext::get()->canOrg('catalog.org_product.override_price');
    }

    protected function mayApproveBelowMinimum(): bool
    {
        return TenantContext::has()
            && TenantContext::get()->canOrg('catalog.org_product.approve_below_minimum');
    }

    protected function requireOverrideAuthority(): void
    {
        abort_unless($this->mayOverridePrice(), 403, 'Price override authority is required.');
    }

    /**
     * A price under the catalog minimum may only be saved by someone who can approve it.
     */
    protected function requireBelowMinimumAuthority(?int $minimumPriceCents, ?int $priceCents): void
    {
        if ($minimumPriceCents === null || $priceCents === null || $priceCents >= $minimumPriceCents) {
            return;
        }

        abort_unless(
            $this->mayApproveBelowMinimum(),
            403,
            'Pricing below the catalog minimum requires below-minimum approval authority.',
        );
    }

    protected function assertQuoteBelongsToDeal(Deal $deal, Quote $quote): void
    {
        abort_unless($quote->deal_id === $deal->id, 404);
    }

    protected function assertRevisionBelongsToQuote(Quote $quote, QuoteRevision $revision): void
    {
        abort_unless($revision->quote_id === $quote->id, 404);
    }

    /**
     * Translate domain refusals into HTTP: invalid payloads become validation errors,
     * frozen revisions become 409. StaleQuoteStateException is already a 409 HttpException.
     *
     * Unresolvable tax positions and impossible approval steps are refusals about the
     * payload the caller sent — an unusable rate, a missing reason, a revision that is
     * not awaiting a decision — so they surface as validation errors alongside the
     * draft ones rather than as conflicts.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $mutation
     * @return TReturn
     */
    protected function runDraftMutation(callable $mutation): mixed
    {
        try {
            return $mutation();
        } catch (
            InvalidQuoteDraftException
            |InvalidQuoteTotalsException
            |InvalidQuoteTaxCalculationException
            |InvalidQuoteApprovalException
            |InvalidArgumentException $exception
        ) {
            throw ValidationException::withMessages(['quote' => $exception->getMessage()]);
        } catch (ImmutableQuoteRevisionException $exception) {
            throw new HttpException(409, $exception->getMessage());
        }
    }
}
