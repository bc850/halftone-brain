<?php

namespace App\Support\Deals;

use App\Enums\DealStage;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\User;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Centralized Deal stage mutations. Quote-controlled stages cannot be set via the generic endpoint.
 */
final class DealStageService
{
    /**
     * @var list<DealStage>
     */
    public const QUOTE_CONTROLLED_STAGES = [
        DealStage::Quoting,
        DealStage::QuoteSent,
        DealStage::QuoteWon,
    ];

    public function __construct(private Auditor $auditor) {}

    public function isQuoteControlled(DealStage $stage): bool
    {
        return in_array($stage, self::QUOTE_CONTROLLED_STAGES, true);
    }

    /**
     * Manual stage change from the generic Deal stage endpoint.
     */
    public function applyManualStage(Deal $deal, DealStage $to, ?User $actor = null): Deal
    {
        if ($this->isQuoteControlled($to)) {
            throw new HttpException(
                409,
                'Deal stage ['.$to->value.'] is controlled by quote lifecycle and cannot be set manually.'
            );
        }

        if ($deal->stage === DealStage::QuoteWon) {
            throw new HttpException(
                409,
                'A won Deal cannot be moved backward through the generic stage endpoint.'
            );
        }

        return $this->applyStage($deal, $to, 'manual', $actor);
    }

    /**
     * Quote-driven stage synchronization (internal).
     */
    public function applyQuoteDrivenStage(Deal $deal, DealStage $to, ?User $actor = null): Deal
    {
        if (! $this->isQuoteControlled($to) && $to !== DealStage::Negotiations && $to !== DealStage::QuoteLost) {
            throw new HttpException(500, 'Invalid quote-driven Deal stage ['.$to->value.'].');
        }

        return $this->applyStage($deal, $to, 'quote_sync', $actor);
    }

    private function applyStage(Deal $deal, DealStage $to, string $source, ?User $actor): Deal
    {
        if ($deal->stage === $to) {
            return $deal;
        }

        return DB::transaction(function () use ($deal, $to, $source, $actor): Deal {
            /** @var Deal $locked */
            $locked = Deal::query()->whereKey($deal->id)->lockForUpdate()->firstOrFail();
            $from = $locked->stage;

            if ($from === $to) {
                return $locked;
            }

            $locked->forceFill(['stage' => $to])->save();

            $parent = $locked->parent_account_id !== null
                ? ParentAccount::query()->find($locked->parent_account_id)
                : null;
            $organization = $locked->organization_id !== null
                ? Organization::query()->find($locked->organization_id)
                : null;

            if ($parent !== null) {
                $this->auditor->append(
                    parentAccount: $parent,
                    action: 'crm.deal.stage_changed',
                    subjectType: Deal::class,
                    subjectId: $locked->id,
                    organization: $organization,
                    actor: $actor,
                    before: ['stage' => $from->value, 'source' => $source],
                    after: ['stage' => $to->value, 'source' => $source],
                );
            }

            return $locked->refresh();
        });
    }
}
