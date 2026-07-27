<?php

namespace App\Support\Quotes;

use App\Enums\QuoteLineType;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\ParentAccount;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionLineItem;
use App\Models\User;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Refreshes catalog lines against current catalog pricing.
 *
 * Custom, section, and note lines are never touched — they carry no catalog identity.
 * The customer-facing overridden price is preserved by default; only the calculated price,
 * cost snapshots, and pricing/components versions move forward.
 */
final class QuoteRepriceService
{
    public function __construct(
        private QuoteDraftLock $lock,
        private QuoteRevisionTotalsSynchronizer $totals,
        private QuoteCatalogLinePricer $pricer,
        private Auditor $auditor,
    ) {}

    public function repriceLine(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedRevisionLockVersion,
        QuoteRevisionLineItem $line,
        bool $preserveOverride = true,
        ?User $actor = null,
    ): QuoteRevisionLineItem {
        return $this->withDraft(
            $quote,
            $revision,
            $expectedRevisionLockVersion,
            'crm.quote.line_repriced',
            $actor,
            function (Quote $lockedQuote, QuoteRevision $lockedRevision) use ($line, $preserveOverride): array {
                $locked = $this->lockCatalogLine($lockedRevision, $line);
                $before = $this->payload($locked);

                $this->applyReprice($lockedQuote, $locked, $preserveOverride);

                return [
                    'result' => $locked->fresh() ?? $locked,
                    'before' => $before,
                    'after' => $this->payload($locked),
                ];
            },
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function repriceAllCatalogLines(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedRevisionLockVersion,
        bool $preserveOverride = true,
        ?User $actor = null,
    ): array {
        return $this->withDraft(
            $quote,
            $revision,
            $expectedRevisionLockVersion,
            'crm.quote.revision_repriced',
            $actor,
            function (Quote $lockedQuote, QuoteRevision $lockedRevision) use ($preserveOverride): array {
                $lines = QuoteRevisionLineItem::query()
                    ->where('quote_revision_id', $lockedRevision->id)
                    ->where('line_type', QuoteLineType::Catalog->value)
                    ->orderBy('position')
                    ->lockForUpdate()
                    ->get();

                $before = [];
                $after = [];

                foreach ($lines as $line) {
                    $before[] = $this->payload($line);
                    $this->applyReprice($lockedQuote, $line, $preserveOverride);
                    $after[] = $this->payload($line);
                }

                return [
                    'result' => $after,
                    'before' => ['lines' => $before],
                    'after' => ['lines' => $after],
                ];
            },
        );
    }

    /**
     * Drop an override and fall back to the current catalog calculated price.
     * The controller owns the "are you sure" confirmation.
     */
    public function resetOverrideToCalculated(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedRevisionLockVersion,
        QuoteRevisionLineItem $line,
        ?User $actor = null,
    ): QuoteRevisionLineItem {
        return $this->withDraft(
            $quote,
            $revision,
            $expectedRevisionLockVersion,
            'crm.quote.line_override_reset',
            $actor,
            function (Quote $lockedQuote, QuoteRevision $lockedRevision) use ($line): array {
                $locked = $this->lockCatalogLine($lockedRevision, $line);
                $before = $this->payload($locked);

                $this->applyReprice($lockedQuote, $locked, preserveOverride: false);

                return [
                    'result' => $locked->fresh() ?? $locked,
                    'before' => $before,
                    'after' => $this->payload($locked),
                ];
            },
        );
    }

    private function applyReprice(Quote $quote, QuoteRevisionLineItem $line, bool $preserveOverride): void
    {
        if ($line->organization_product_id === null) {
            throw new InvalidQuoteDraftException('Catalog line is missing organization product traceability.');
        }

        $organizationProduct = OrganizationProduct::query()
            ->with('product')
            ->findOrFail($line->organization_product_id);

        $attributes = $this->pricer->priceForReprice($quote, $line, $organizationProduct, $preserveOverride);

        // Reprice moves money, costs, and version snapshots only. Presentation snapshots and
        // quantity stay as the quote author left them.
        unset(
            $attributes['line_type'],
            $attributes['name_snapshot'],
            $attributes['sku_snapshot'],
            $attributes['item_kind_snapshot'],
            $attributes['uom_snapshot'],
            $attributes['customer_description_snapshot'],
            $attributes['internal_description_snapshot'],
            $attributes['quantity_scaled'],
            $attributes['is_taxable'],
        );

        $line->fill($attributes);
        $line->save();
    }

    private function lockCatalogLine(QuoteRevision $revision, QuoteRevisionLineItem $line): QuoteRevisionLineItem
    {
        $locked = QuoteRevisionLineItem::query()
            ->whereKey($line->id)
            ->where('quote_revision_id', $revision->id)
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            throw new InvalidQuoteDraftException('Line does not belong to the given quote revision.');
        }

        if ($locked->line_type !== QuoteLineType::Catalog) {
            throw new InvalidQuoteDraftException('Only catalog lines can be repriced.');
        }

        return $locked;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(QuoteRevisionLineItem $line): array
    {
        return [
            'id' => $line->id,
            'organization_product_id' => $line->organization_product_id,
            'calculated_unit_price_cents' => $line->calculated_unit_price_cents,
            'final_unit_price_cents' => $line->final_unit_price_cents,
            'price_override' => $line->price_override,
            'below_minimum' => $line->below_minimum,
            'total_cost_micro_units' => $line->total_cost_micro_units,
            'pricing_version_snapshot' => $line->pricing_version_snapshot,
            'components_version_snapshot' => $line->components_version_snapshot,
            'approval_required' => $line->approval_required,
        ];
    }

    /**
     * @param  callable(Quote, QuoteRevision): array{result: mixed, before?: array<string, mixed>|null, after?: array<string, mixed>|null}  $mutation
     */
    private function withDraft(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedRevisionLockVersion,
        string $action,
        ?User $actor,
        callable $mutation,
    ): mixed {
        return DB::transaction(function () use (
            $quote,
            $revision,
            $expectedRevisionLockVersion,
            $action,
            $actor,
            $mutation,
        ): mixed {
            ['quote' => $lockedQuote, 'revision' => $lockedRevision] = $this->lock->lockDraft(
                $quote,
                $revision,
                $expectedRevisionLockVersion,
            );

            $outcome = $mutation($lockedQuote, $lockedRevision);

            $this->totals->sync($lockedRevision, $actor);
            $this->lock->bumpRevisionLock($lockedRevision);

            $this->auditor->append(
                parentAccount: ParentAccount::query()->findOrFail($lockedQuote->parent_account_id),
                action: $action,
                subjectType: QuoteRevision::class,
                subjectId: $lockedRevision->id,
                organization: Organization::query()->findOrFail($lockedQuote->organization_id),
                actor: $actor,
                before: $outcome['before'] ?? null,
                after: $outcome['after'] ?? null,
                correlationId: (string) Str::uuid(),
            );

            return $outcome['result'];
        });
    }
}
