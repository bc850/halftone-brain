<?php

namespace App\Support\Deals;

use App\Enums\DealStage;
use App\Enums\QuoteLifecycleStatus;
use App\Enums\QuoteRevisionStatus;
use App\Models\Deal;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\User;

/**
 * Maps quote/revision lifecycle events onto Deal stages.
 * Never fabricates quote status from Deal stage changes.
 */
final class DealQuoteStageSynchronizer
{
    public function __construct(private DealStageService $dealStages) {}

    public function onQuoteCreated(Quote $quote, ?User $actor = null): void
    {
        $deal = Deal::query()->findOrFail($quote->deal_id);

        if (in_array($deal->stage, [DealStage::Lead, DealStage::Qualified], true)) {
            $this->dealStages->applyQuoteDrivenStage($deal, DealStage::Quoting, $actor);
        }
    }

    public function onRevisionTransitioned(Quote $quote, QuoteRevision $revision, QuoteRevisionStatus $to, ?User $actor = null): void
    {
        $deal = Deal::query()->findOrFail($quote->deal_id);

        match ($to) {
            QuoteRevisionStatus::Sent => $this->dealStages->applyQuoteDrivenStage($deal, DealStage::QuoteSent, $actor),
            QuoteRevisionStatus::Accepted => $this->dealStages->applyQuoteDrivenStage($deal, DealStage::QuoteWon, $actor),
            QuoteRevisionStatus::Rejected, QuoteRevisionStatus::Expired => $this->syncAfterNegativeTerminal($deal, $quote, $to, $actor),
            default => null,
        };
    }

    private function syncAfterNegativeTerminal(
        Deal $deal,
        Quote $quote,
        QuoteRevisionStatus $to,
        ?User $actor,
    ): void {
        if ($deal->stage === DealStage::QuoteWon) {
            return;
        }

        if ($this->dealHasOtherActiveQuotes($deal, $quote)) {
            if ($deal->stage === DealStage::QuoteSent) {
                $this->dealStages->applyQuoteDrivenStage($deal, DealStage::Negotiations, $actor);
            }

            return;
        }

        // No other active quote work — mark lost for customer reject / expire.
        if (in_array($to, [QuoteRevisionStatus::Rejected, QuoteRevisionStatus::Expired], true)) {
            $this->dealStages->applyQuoteDrivenStage($deal, DealStage::QuoteLost, $actor);
        }
    }

    private function dealHasOtherActiveQuotes(Deal $deal, Quote $except): bool
    {
        return Quote::query()
            ->where('deal_id', $deal->id)
            ->where('organization_id', $deal->organization_id)
            ->whereKeyNot($except->id)
            ->where('lifecycle_status', QuoteLifecycleStatus::Open->value)
            ->exists();
    }
}
