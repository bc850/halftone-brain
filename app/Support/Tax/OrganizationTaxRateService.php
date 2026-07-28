<?php

namespace App\Support\Tax;

use App\Models\Organization;
use App\Models\OrganizationTaxRate;
use Illuminate\Support\Carbon;

/**
 * Reads and validates an organization's configured jurisdiction rates.
 *
 * Two rows for the same jurisdiction may not cover the same day. MySQL has no
 * exclusion constraint for date ranges, so the rule lives here and every write
 * path must call `assertNoOverlap()` before saving.
 */
class OrganizationTaxRateService
{
    /**
     * @throws OverlappingTaxRateException
     */
    public function assertNoOverlap(
        Organization|int $organization,
        string $jurisdictionCode,
        Carbon|string $effectiveFrom,
        Carbon|string|null $effectiveThrough = null,
        ?int $exceptId = null,
    ): void {
        $from = $this->toDate($effectiveFrom);
        $through = $effectiveThrough === null ? null : $this->toDate($effectiveThrough);

        if ($through !== null && $through->lt($from)) {
            throw new OverlappingTaxRateException(
                'Tax rate effective_through cannot precede effective_from.'
            );
        }

        $query = OrganizationTaxRate::query()
            ->where('organization_id', $this->organizationId($organization))
            ->where('jurisdiction_code', $jurisdictionCode)
            ->where('effective_from', '<=', $through?->toDateString() ?? '9999-12-31')
            ->where(function ($builder) use ($from): void {
                $builder->whereNull('effective_through')
                    ->orWhere('effective_through', '>=', $from->toDateString());
            });

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        $conflict = $query->orderBy('effective_from')->first();

        if ($conflict === null) {
            return;
        }

        throw new OverlappingTaxRateException(sprintf(
            'Tax rate for jurisdiction [%s] overlaps existing rate [%d] effective %s to %s.',
            $jurisdictionCode,
            $conflict->id,
            $conflict->effective_from->toDateString(),
            $conflict->effective_through?->toDateString() ?? 'open',
        ));
    }

    /**
     * The active rate covering `$asOf`, or null when the organization has none.
     *
     * Overlaps are prevented on write, so at most one row can match; the ordering
     * only makes the result deterministic if historical data ever violates that.
     */
    public function selectEffectiveRate(
        Organization|int $organization,
        string $jurisdictionCode,
        Carbon|string $asOf,
    ): ?OrganizationTaxRate {
        $date = $this->toDate($asOf)->toDateString();

        return OrganizationTaxRate::query()
            ->where('organization_id', $this->organizationId($organization))
            ->where('jurisdiction_code', $jurisdictionCode)
            ->where('is_active', true)
            ->where('effective_from', '<=', $date)
            ->where(function ($builder) use ($date): void {
                $builder->whereNull('effective_through')
                    ->orWhere('effective_through', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    private function organizationId(Organization|int $organization): int
    {
        return $organization instanceof Organization ? $organization->id : $organization;
    }

    private function toDate(Carbon|string $value): Carbon
    {
        return $value instanceof Carbon
            ? $value->copy()->startOfDay()
            : Carbon::parse($value)->startOfDay();
    }
}
