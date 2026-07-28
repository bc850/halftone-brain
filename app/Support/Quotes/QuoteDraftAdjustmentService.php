<?php

namespace App\Support\Quotes;

use App\Enums\QuoteAdjustmentMethod;
use App\Enums\QuoteAdjustmentType;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionAdjustment;
use App\Models\User;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Draft-only quote-level adjustments: discounts and positive charges.
 *
 * Discount authority and quote-update permission are resolved by the caller and
 * passed in as `$mayOverride`; the domain enforces that a discount always records a reason.
 */
final class QuoteDraftAdjustmentService
{
    public function __construct(
        private QuoteDraftLock $lock,
        private QuoteRevisionTotalsSynchronizer $totals,
        private QuoteApprovalInvalidationService $invalidation,
        private Auditor $auditor,
    ) {}

    public function add(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedRevisionLockVersion,
        QuoteAdjustmentType $adjustmentType,
        string $description,
        QuoteAdjustmentMethod $method,
        int $inputValue,
        bool $isTaxable = false,
        ?string $reason = null,
        bool $mayOverride = false,
        ?User $actor = null,
    ): QuoteRevisionAdjustment {
        return $this->withDraft(
            $quote,
            $revision,
            $expectedRevisionLockVersion,
            'crm.quote.adjustment_added',
            $actor,
            function (Quote $lockedQuote, QuoteRevision $lockedRevision) use (
                $adjustmentType,
                $description,
                $method,
                $inputValue,
                $isTaxable,
                $reason,
                $mayOverride,
            ): array {
                $this->assertAdjustment($adjustmentType, $description, $method, $inputValue, $reason, $mayOverride);

                $adjustment = QuoteRevisionAdjustment::query()->create([
                    'parent_account_id' => $lockedRevision->parent_account_id,
                    'organization_id' => $lockedRevision->organization_id,
                    'quote_id' => $lockedQuote->id,
                    'quote_revision_id' => $lockedRevision->id,
                    'position' => $this->nextPosition($lockedRevision),
                    'adjustment_type' => $adjustmentType,
                    'description_snapshot' => trim($description),
                    'method' => $method,
                    'input_value' => $inputValue,
                    'amount_cents' => 0,
                    'is_taxable' => $adjustmentType->isDiscount() ? false : $isTaxable,
                    'approval_required' => $adjustmentType->isDiscount(),
                    'approval_reason_json' => $this->reasonJson($adjustmentType, $reason),
                ]);

                return ['result' => $adjustment, 'after' => $this->payload($adjustment)];
            },
        );
    }

    /**
     * @param  array{
     *     description_snapshot?: string,
     *     method?: QuoteAdjustmentMethod|string,
     *     input_value?: int,
     *     is_taxable?: bool,
     *     reason?: string|null
     * }  $data
     */
    public function update(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedRevisionLockVersion,
        QuoteRevisionAdjustment $adjustment,
        array $data,
        bool $mayOverride = false,
        ?User $actor = null,
    ): QuoteRevisionAdjustment {
        return $this->withDraft(
            $quote,
            $revision,
            $expectedRevisionLockVersion,
            'crm.quote.adjustment_updated',
            $actor,
            function (Quote $lockedQuote, QuoteRevision $lockedRevision) use ($adjustment, $data, $mayOverride): array {
                $locked = $this->lockAdjustment($lockedRevision, $adjustment);
                $before = $this->payload($locked);

                $description = array_key_exists('description_snapshot', $data)
                    ? (string) $data['description_snapshot']
                    : $locked->description_snapshot;

                $method = $data['method'] ?? $locked->method;
                $method = $method instanceof QuoteAdjustmentMethod
                    ? $method
                    : QuoteAdjustmentMethod::from((string) $method);

                $inputValue = array_key_exists('input_value', $data)
                    ? (int) $data['input_value']
                    : $locked->input_value;

                $reason = array_key_exists('reason', $data)
                    ? $data['reason']
                    : ($locked->approval_reason_json['reason'] ?? null);

                $this->assertAdjustment(
                    $locked->adjustment_type,
                    $description,
                    $method,
                    $inputValue,
                    is_string($reason) ? $reason : null,
                    $mayOverride,
                );

                $locked->fill([
                    'description_snapshot' => trim($description),
                    'method' => $method,
                    'input_value' => $inputValue,
                    'is_taxable' => $locked->adjustment_type->isDiscount()
                        ? false
                        : (bool) ($data['is_taxable'] ?? $locked->is_taxable),
                    'approval_reason_json' => $this->reasonJson(
                        $locked->adjustment_type,
                        is_string($reason) ? $reason : null,
                    ),
                ]);
                $locked->save();

                return [
                    'result' => $locked->fresh() ?? $locked,
                    'before' => $before,
                    'after' => $this->payload($locked),
                ];
            },
        );
    }

    public function remove(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedRevisionLockVersion,
        QuoteRevisionAdjustment $adjustment,
        ?User $actor = null,
    ): void {
        $this->withDraft(
            $quote,
            $revision,
            $expectedRevisionLockVersion,
            'crm.quote.adjustment_removed',
            $actor,
            function (Quote $lockedQuote, QuoteRevision $lockedRevision) use ($adjustment): array {
                $locked = $this->lockAdjustment($lockedRevision, $adjustment);
                $before = $this->payload($locked);
                $locked->delete();

                return ['result' => null, 'before' => $before];
            },
        );
    }

    private function assertAdjustment(
        QuoteAdjustmentType $adjustmentType,
        string $description,
        QuoteAdjustmentMethod $method,
        int $inputValue,
        ?string $reason,
        bool $mayOverride,
    ): void {
        if (trim($description) === '') {
            throw new InvalidQuoteDraftException('Adjustment description is required.');
        }

        if ($inputValue < 0) {
            throw new InvalidQuoteDraftException('Adjustment value cannot be negative.');
        }

        if ($adjustmentType->isDiscount()) {
            if (! $mayOverride) {
                throw new InvalidQuoteDraftException('Quote discounts require discount authority.');
            }

            if ($reason === null || trim($reason) === '') {
                throw new InvalidQuoteDraftException('Quote discounts require a reason.');
            }

            return;
        }

        if ($method !== QuoteAdjustmentMethod::Fixed) {
            throw new InvalidQuoteDraftException('Positive adjustments must use a fixed cent amount.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reasonJson(QuoteAdjustmentType $adjustmentType, ?string $reason): ?array
    {
        if (! $adjustmentType->isDiscount()) {
            return null;
        }

        return [
            'reasons' => [QuoteApprovalReasonAggregator::REASON_QUOTE_DISCOUNT],
            'reason' => $reason === null ? null : trim($reason),
        ];
    }

    private function nextPosition(QuoteRevision $revision): int
    {
        return (int) QuoteRevisionAdjustment::query()
            ->where('quote_revision_id', $revision->id)
            ->max('position') + 1;
    }

    private function lockAdjustment(
        QuoteRevision $revision,
        QuoteRevisionAdjustment $adjustment,
    ): QuoteRevisionAdjustment {
        $locked = QuoteRevisionAdjustment::query()
            ->whereKey($adjustment->id)
            ->where('quote_revision_id', $revision->id)
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            throw new InvalidQuoteDraftException('Adjustment does not belong to the given quote revision.');
        }

        return $locked;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(QuoteRevisionAdjustment $adjustment): array
    {
        return [
            'id' => $adjustment->id,
            'position' => $adjustment->position,
            'adjustment_type' => $adjustment->adjustment_type->value,
            'description_snapshot' => $adjustment->description_snapshot,
            'method' => $adjustment->method->value,
            'input_value' => $adjustment->input_value,
            'amount_cents' => $adjustment->amount_cents,
            'is_taxable' => $adjustment->is_taxable,
            'approval_required' => $adjustment->approval_required,
            'approval_reason_json' => $adjustment->approval_reason_json,
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
