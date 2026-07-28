<?php

namespace App\Support\Quotes;

use App\Enums\QuoteLineDiscountMethod;
use App\Enums\QuoteLineType;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\ParentAccount;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionLineItem;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Catalog\ComponentCost\InvalidComponentCostException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Draft-only line editing. Every method locks the draft, mutates, resynchronizes totals,
 * bumps the revision lock exactly once, and writes one audit entry.
 *
 * Permission checks (quote update, price override authority) belong to the caller; the
 * `$mayOverride` flag carries the resolved decision into the domain.
 */
final class QuoteDraftLineService
{
    private const REORDER_OFFSET = 1_000_000;

    public function __construct(
        private QuoteDraftLock $lock,
        private QuoteRevisionTotalsSynchronizer $totals,
        private QuoteCatalogLinePricer $pricer,
        private QuoteApprovalInvalidationService $invalidation,
        private Auditor $auditor,
    ) {}

    public function addCatalogLine(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedRevisionLockVersion,
        OrganizationProduct $organizationProduct,
        string $quantity,
        ?int $overrideUnitPriceCents = null,
        ?string $overrideReason = null,
        bool $isTaxable = true,
        ?string $customerDescription = null,
        ?string $internalDescription = null,
        bool $mayOverride = false,
        ?User $actor = null,
    ): QuoteRevisionLineItem {
        return $this->withDraft(
            $quote,
            $revision,
            $expectedRevisionLockVersion,
            'crm.quote.line_added',
            $actor,
            function (Quote $lockedQuote, QuoteRevision $lockedRevision) use (
                $organizationProduct,
                $quantity,
                $overrideUnitPriceCents,
                $overrideReason,
                $isTaxable,
                $customerDescription,
                $internalDescription,
                $mayOverride,
            ): array {
                if ($overrideUnitPriceCents !== null && ! $mayOverride) {
                    throw new InvalidQuoteDraftException('Price overrides require override authority.');
                }

                $attributes = $this->pricer->priceForNewLine(
                    quote: $lockedQuote,
                    organizationProduct: $organizationProduct,
                    quantity: $quantity,
                    overrideUnitPriceCents: $overrideUnitPriceCents,
                    overrideReason: $overrideReason,
                    isTaxable: $isTaxable,
                    customerDescription: $customerDescription,
                    internalDescription: $internalDescription,
                );

                $line = $this->createLine($lockedQuote, $lockedRevision, $attributes);

                return ['result' => $line, 'after' => $this->linePayload($line)];
            },
        );
    }

    /**
     * Custom lines carry no catalog traceability and no cost snapshots, so they always
     * require approval and a written justification.
     */
    public function addCustomLine(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedRevisionLockVersion,
        string $name,
        string $quantity,
        int $unitPriceCents,
        string $reason,
        ?string $customerDescription = null,
        ?string $internalDescription = null,
        ?string $uom = null,
        bool $isTaxable = true,
        bool $mayOverride = false,
        ?User $actor = null,
    ): QuoteRevisionLineItem {
        return $this->withDraft(
            $quote,
            $revision,
            $expectedRevisionLockVersion,
            'crm.quote.line_added',
            $actor,
            function (Quote $lockedQuote, QuoteRevision $lockedRevision) use (
                $name,
                $quantity,
                $unitPriceCents,
                $reason,
                $customerDescription,
                $internalDescription,
                $uom,
                $isTaxable,
                $mayOverride,
            ): array {
                if (! $mayOverride) {
                    throw new InvalidQuoteDraftException('Custom lines require price override authority.');
                }

                if (trim($reason) === '') {
                    throw new InvalidQuoteDraftException('Custom lines require a reason.');
                }

                if (trim($name) === '') {
                    throw new InvalidQuoteDraftException('Custom lines require a name.');
                }

                if ($unitPriceCents < 0) {
                    throw new InvalidQuoteDraftException('Custom line unit price cannot be negative.');
                }

                $line = $this->createLine($lockedQuote, $lockedRevision, [
                    'line_type' => QuoteLineType::Custom,
                    'product_id' => null,
                    'organization_product_id' => null,
                    'sku_snapshot' => null,
                    'name_snapshot' => trim($name),
                    'customer_description_snapshot' => $customerDescription,
                    'internal_description_snapshot' => $internalDescription,
                    'item_kind_snapshot' => null,
                    'quantity_scaled' => $this->quantityToScaled($quantity),
                    'uom_snapshot' => $uom,
                    'calculated_unit_price_cents' => $unitPriceCents,
                    'final_unit_price_cents' => $unitPriceCents,
                    'is_taxable' => $isTaxable,
                    'price_override' => false,
                    'override_reason' => trim($reason),
                    'below_minimum' => false,
                    'approval_required' => true,
                    'approval_reason_json' => ['reasons' => [QuoteApprovalReasonAggregator::REASON_CUSTOM_LINE]],
                    'material_cost_micro_units' => null,
                    'labor_cost_micro_units' => null,
                    'overhead_cost_micro_units' => null,
                    'total_cost_micro_units' => null,
                ]);

                return ['result' => $line, 'after' => $this->linePayload($line)];
            },
        );
    }

    public function addSectionLine(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedRevisionLockVersion,
        string $name,
        ?string $customerDescription = null,
        ?User $actor = null,
    ): QuoteRevisionLineItem {
        return $this->addPresentationLine(
            $quote,
            $revision,
            $expectedRevisionLockVersion,
            QuoteLineType::Section,
            $name,
            $customerDescription,
            $actor,
        );
    }

    public function addNoteLine(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedRevisionLockVersion,
        string $name,
        ?string $customerDescription = null,
        ?User $actor = null,
    ): QuoteRevisionLineItem {
        return $this->addPresentationLine(
            $quote,
            $revision,
            $expectedRevisionLockVersion,
            QuoteLineType::Note,
            $name,
            $customerDescription,
            $actor,
        );
    }

    /**
     * @param  array{
     *     name_snapshot?: string,
     *     customer_description_snapshot?: string|null,
     *     internal_description_snapshot?: string|null,
     *     uom_snapshot?: string|null,
     *     quantity?: string,
     *     is_taxable?: bool,
     *     final_unit_price_cents?: int,
     *     override_reason?: string|null,
     *     line_discount_method?: QuoteLineDiscountMethod|string,
     *     line_discount_value?: int
     * }  $data
     */
    public function updateLine(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedRevisionLockVersion,
        QuoteRevisionLineItem $line,
        array $data,
        bool $mayOverride = false,
        ?User $actor = null,
    ): QuoteRevisionLineItem {
        return $this->withDraft(
            $quote,
            $revision,
            $expectedRevisionLockVersion,
            'crm.quote.line_updated',
            $actor,
            function (Quote $lockedQuote, QuoteRevision $lockedRevision) use ($line, $data, $mayOverride): array {
                $locked = $this->lockLine($lockedRevision, $line);
                $before = $this->linePayload($locked);

                $this->applyCommonUpdates($locked, $data);

                if ($locked->line_type->isFinancial()) {
                    $this->applyFinancialUpdates($locked, $data, $mayOverride);
                }

                $locked->save();

                return [
                    'result' => $locked->fresh() ?? $locked,
                    'before' => $before,
                    'after' => $this->linePayload($locked),
                ];
            },
        );
    }

    /**
     * Draft lines are hard deleted; the schema keeps no soft-delete column because a sent
     * revision is immutable and history lives on the superseded revision.
     */
    public function removeLine(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedRevisionLockVersion,
        QuoteRevisionLineItem $line,
        ?User $actor = null,
    ): void {
        $this->withDraft(
            $quote,
            $revision,
            $expectedRevisionLockVersion,
            'crm.quote.line_removed',
            $actor,
            function (Quote $lockedQuote, QuoteRevision $lockedRevision) use ($line): array {
                $locked = $this->lockLine($lockedRevision, $line);
                $before = $this->linePayload($locked);
                $locked->delete();

                return ['result' => null, 'before' => $before];
            },
        );
    }

    /**
     * @param  list<int>  $orderedLineIds
     * @return Collection<int, QuoteRevisionLineItem>
     */
    public function reorderLines(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedRevisionLockVersion,
        array $orderedLineIds,
        ?User $actor = null,
    ): Collection {
        return $this->withDraft(
            $quote,
            $revision,
            $expectedRevisionLockVersion,
            'crm.quote.lines_reordered',
            $actor,
            function (Quote $lockedQuote, QuoteRevision $lockedRevision) use ($orderedLineIds): array {
                $existing = QuoteRevisionLineItem::query()
                    ->where('quote_revision_id', $lockedRevision->id)
                    ->orderBy('position')
                    ->orderBy('id')
                    ->get();

                $existingIds = $existing->pluck('id')->all();
                $requested = $orderedLineIds;

                sort($existingIds);
                $sortedRequested = $requested;
                sort($sortedRequested);

                if ($existingIds !== $sortedRequested) {
                    throw new InvalidQuoteDraftException(
                        'Reorder must list every line on the revision exactly once.'
                    );
                }

                $before = ['order' => $existing->pluck('id')->all()];
                $now = now();

                // Two passes: the (quote_revision_id, position) unique index rejects an
                // in-place swap, so park every row above the used range first.
                foreach ($requested as $index => $id) {
                    QuoteRevisionLineItem::query()
                        ->whereKey($id)
                        ->where('quote_revision_id', $lockedRevision->id)
                        ->update(['position' => self::REORDER_OFFSET + $index + 1, 'updated_at' => $now]);
                }

                foreach ($requested as $index => $id) {
                    QuoteRevisionLineItem::query()
                        ->whereKey($id)
                        ->where('quote_revision_id', $lockedRevision->id)
                        ->update(['position' => $index + 1, 'updated_at' => $now]);
                }

                $reordered = QuoteRevisionLineItem::query()
                    ->where('quote_revision_id', $lockedRevision->id)
                    ->orderBy('position')
                    ->get();

                return [
                    'result' => $reordered,
                    'before' => $before,
                    'after' => ['order' => $reordered->pluck('id')->all()],
                ];
            },
        );
    }

    private function addPresentationLine(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedRevisionLockVersion,
        QuoteLineType $lineType,
        string $name,
        ?string $customerDescription,
        ?User $actor,
    ): QuoteRevisionLineItem {
        return $this->withDraft(
            $quote,
            $revision,
            $expectedRevisionLockVersion,
            'crm.quote.line_added',
            $actor,
            function (Quote $lockedQuote, QuoteRevision $lockedRevision) use ($lineType, $name, $customerDescription): array {
                if (trim($name) === '') {
                    throw new InvalidQuoteDraftException("A {$lineType->value} line requires a name.");
                }

                $line = $this->createLine($lockedQuote, $lockedRevision, [
                    'line_type' => $lineType,
                    'product_id' => null,
                    'organization_product_id' => null,
                    'sku_snapshot' => null,
                    'name_snapshot' => trim($name),
                    'customer_description_snapshot' => $customerDescription,
                    'internal_description_snapshot' => null,
                    'item_kind_snapshot' => null,
                    'quantity_scaled' => 0,
                    'uom_snapshot' => null,
                    'calculated_unit_price_cents' => null,
                    'final_unit_price_cents' => null,
                    'extended_price_cents' => 0,
                    'line_discount_method' => QuoteLineDiscountMethod::None,
                    'line_discount_value' => 0,
                    'line_discount_amount_cents' => 0,
                    'net_line_total_cents' => 0,
                    'is_taxable' => false,
                    'price_override' => false,
                    'override_reason' => null,
                    'below_minimum' => false,
                    'approval_required' => false,
                    'approval_reason_json' => null,
                    'material_cost_micro_units' => null,
                    'labor_cost_micro_units' => null,
                    'overhead_cost_micro_units' => null,
                    'total_cost_micro_units' => null,
                ]);

                return ['result' => $line, 'after' => $this->linePayload($line)];
            },
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyCommonUpdates(QuoteRevisionLineItem $line, array $data): void
    {
        if (array_key_exists('name_snapshot', $data)) {
            $name = trim((string) $data['name_snapshot']);
            if ($name === '') {
                throw new InvalidQuoteDraftException('Line name cannot be empty.');
            }

            $line->name_snapshot = $name;
        }

        if (array_key_exists('customer_description_snapshot', $data)) {
            $line->customer_description_snapshot = $data['customer_description_snapshot'];
        }

        if (array_key_exists('internal_description_snapshot', $data)) {
            $line->internal_description_snapshot = $data['internal_description_snapshot'];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyFinancialUpdates(QuoteRevisionLineItem $line, array $data, bool $mayOverride): void
    {
        if (array_key_exists('uom_snapshot', $data)) {
            $line->uom_snapshot = $data['uom_snapshot'];
        }

        if (array_key_exists('quantity', $data)) {
            $line->quantity_scaled = $this->quantityToScaled((string) $data['quantity']);
        }

        if (array_key_exists('is_taxable', $data)) {
            $line->is_taxable = (bool) $data['is_taxable'];
        }

        if (array_key_exists('override_reason', $data)) {
            $line->override_reason = $data['override_reason'] === null
                ? null
                : trim((string) $data['override_reason']);
        }

        if (array_key_exists('final_unit_price_cents', $data)) {
            $this->applyPriceUpdate($line, (int) $data['final_unit_price_cents'], $mayOverride);
        }

        if (array_key_exists('line_discount_method', $data) || array_key_exists('line_discount_value', $data)) {
            $this->applyDiscountUpdate($line, $data);
        }

        $this->applyApprovalFlags($line);
    }

    private function applyPriceUpdate(QuoteRevisionLineItem $line, int $finalUnitPriceCents, bool $mayOverride): void
    {
        if ($finalUnitPriceCents < 0) {
            throw new InvalidQuoteDraftException('Unit price cannot be negative.');
        }

        if ($line->line_type === QuoteLineType::Custom) {
            if (! $mayOverride) {
                throw new InvalidQuoteDraftException('Custom line pricing requires price override authority.');
            }

            $line->calculated_unit_price_cents = $finalUnitPriceCents;
            $line->final_unit_price_cents = $finalUnitPriceCents;

            if ($line->override_reason === null || $line->override_reason === '') {
                throw new InvalidQuoteDraftException('Custom lines require a reason.');
            }

            return;
        }

        $isOverride = $finalUnitPriceCents !== $line->calculated_unit_price_cents;

        if ($isOverride && ! $mayOverride) {
            throw new InvalidQuoteDraftException('Price overrides require override authority.');
        }

        if ($isOverride && ($line->override_reason === null || $line->override_reason === '')) {
            throw new InvalidQuoteDraftException('A price override requires a reason.');
        }

        $line->final_unit_price_cents = $finalUnitPriceCents;
        $line->price_override = $isOverride;

        if (! $isOverride) {
            $line->override_reason = null;
        }

        $line->below_minimum = $this->isBelowMinimum($line, $finalUnitPriceCents);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyDiscountUpdate(QuoteRevisionLineItem $line, array $data): void
    {
        $method = $data['line_discount_method'] ?? $line->line_discount_method;
        $method = $method instanceof QuoteLineDiscountMethod
            ? $method
            : QuoteLineDiscountMethod::from((string) $method);

        $value = array_key_exists('line_discount_value', $data)
            ? (int) $data['line_discount_value']
            : $line->line_discount_value;

        if ($method === QuoteLineDiscountMethod::None) {
            $value = 0;
        }

        if ($value < 0) {
            throw new InvalidQuoteDraftException('Line discount cannot be negative.');
        }

        $line->line_discount_method = $method;
        $line->line_discount_value = $value;
    }

    private function isBelowMinimum(QuoteRevisionLineItem $line, int $finalUnitPriceCents): bool
    {
        $minimum = $line->pricing_input_snapshot_json['minimum_price_cents'] ?? null;

        return is_int($minimum) && $finalUnitPriceCents < $minimum;
    }

    private function applyApprovalFlags(QuoteRevisionLineItem $line): void
    {
        $reasons = [];

        if ($line->line_type === QuoteLineType::Custom) {
            $reasons[] = QuoteApprovalReasonAggregator::REASON_CUSTOM_LINE;
        }

        if ($line->price_override) {
            $reasons[] = QuoteApprovalReasonAggregator::REASON_PRICE_OVERRIDE;

            if ($line->calculated_unit_price_cents !== null
                && $line->final_unit_price_cents !== null
                && $line->final_unit_price_cents < $line->calculated_unit_price_cents) {
                $reasons[] = QuoteApprovalReasonAggregator::REASON_MARGIN_OVERRIDE;
            }
        }

        if ($line->below_minimum) {
            $reasons[] = QuoteApprovalReasonAggregator::REASON_BELOW_MINIMUM;
        }

        $reasons = array_values(array_unique($reasons));

        $line->approval_required = $reasons !== [];
        $line->approval_reason_json = $reasons === [] ? null : ['reasons' => $reasons];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createLine(Quote $quote, QuoteRevision $revision, array $attributes): QuoteRevisionLineItem
    {
        return QuoteRevisionLineItem::query()->create([
            ...$attributes,
            'parent_account_id' => $revision->parent_account_id,
            'organization_id' => $revision->organization_id,
            'quote_id' => $quote->id,
            'quote_revision_id' => $revision->id,
            'position' => $this->nextPosition($revision),
        ]);
    }

    private function nextPosition(QuoteRevision $revision): int
    {
        return (int) QuoteRevisionLineItem::query()
            ->where('quote_revision_id', $revision->id)
            ->max('position') + 1;
    }

    private function lockLine(QuoteRevision $revision, QuoteRevisionLineItem $line): QuoteRevisionLineItem
    {
        $locked = QuoteRevisionLineItem::query()
            ->whereKey($line->id)
            ->where('quote_revision_id', $revision->id)
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            throw new InvalidQuoteDraftException('Line does not belong to the given quote revision.');
        }

        return $locked;
    }

    private function quantityToScaled(string $quantity): int
    {
        try {
            $scaled = ComponentCostEstimator::quantityToScaled($quantity);
        } catch (InvalidComponentCostException $exception) {
            throw new InvalidQuoteDraftException($exception->getMessage(), 0, $exception);
        }

        if ($scaled < 1) {
            throw new InvalidQuoteDraftException('Line quantity must be greater than zero.');
        }

        return $scaled;
    }

    /**
     * @return array<string, mixed>
     */
    private function linePayload(QuoteRevisionLineItem $line): array
    {
        return [
            'id' => $line->id,
            'position' => $line->position,
            'line_type' => $line->line_type->value,
            'name_snapshot' => $line->name_snapshot,
            'organization_product_id' => $line->organization_product_id,
            'quantity_scaled' => $line->quantity_scaled,
            'calculated_unit_price_cents' => $line->calculated_unit_price_cents,
            'final_unit_price_cents' => $line->final_unit_price_cents,
            'line_discount_method' => $line->line_discount_method->value,
            'line_discount_value' => $line->line_discount_value,
            'net_line_total_cents' => $line->net_line_total_cents,
            'is_taxable' => $line->is_taxable,
            'price_override' => $line->price_override,
            'below_minimum' => $line->below_minimum,
            'approval_required' => $line->approval_required,
            'approval_reason_json' => $line->approval_reason_json,
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
            $correlationId = (string) Str::uuid();

            // Money moved, so any tax figure and any pending approval stop describing
            // this revision. The bump below is the single one for this action.
            $this->invalidation->invalidateForFinancialMutation(
                $lockedQuote,
                $lockedRevision,
                $actor,
                $correlationId,
            );

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
                correlationId: $correlationId,
            );

            return $outcome['result'];
        });
    }
}
