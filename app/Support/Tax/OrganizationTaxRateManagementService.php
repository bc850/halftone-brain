<?php

namespace App\Support\Tax;

use App\Models\Organization;
use App\Models\OrganizationTaxRate;
use App\Models\ParentAccount;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Writes an organization's configured jurisdiction rates.
 *
 * Rates are historical facts: a calculation made last quarter must stay explainable
 * by the row that produced it. So a rate change never edits `rate_ppm` in place —
 * {@see supersede()} closes the current row and opens a new one from the day the new
 * rate takes effect, and {@see deactivate()} stops future use without deleting
 * anything. Only labelling and the closing date can be edited directly.
 *
 * Percentages arrive as decimal strings and are converted to parts per million with
 * BCMath, so no rate ever passes through a float.
 *
 * Permission checks belong to the caller. Nothing here reads TenantContext.
 */
final class OrganizationTaxRateManagementService
{
    /**
     * @var list<string>
     */
    private const EDITABLE_FIELDS = [
        'display_name',
        'source_note',
        'routing_metadata_json',
        'effective_through',
    ];

    public function __construct(
        private OrganizationTaxRateService $rates,
        private Auditor $auditor,
    ) {}

    /**
     * @param  string  $ratePercent  decimal percentage string, e.g. "8.5" for 8.5%
     * @param  array<string, mixed>|null  $routingMetadata
     */
    public function create(
        Organization $organization,
        string $jurisdictionCode,
        string $displayName,
        string $ratePercent,
        CarbonInterface|string $effectiveFrom,
        CarbonInterface|string|null $effectiveThrough = null,
        string $country = 'US',
        ?string $state = null,
        ?string $county = null,
        ?string $city = null,
        ?string $postalCode = null,
        ?string $sourceNote = null,
        ?array $routingMetadata = null,
        ?User $actor = null,
    ): OrganizationTaxRate {
        $jurisdictionCode = $this->requireText($jurisdictionCode, 'Jurisdiction code');
        $displayName = $this->requireText($displayName, 'Jurisdiction display name');
        $ratePpm = $this->toRatePartsPerMillion($ratePercent);
        $from = $this->toDateString($effectiveFrom);
        $through = $effectiveThrough === null ? null : $this->toDateString($effectiveThrough);

        return DB::transaction(function () use (
            $organization,
            $jurisdictionCode,
            $displayName,
            $ratePpm,
            $from,
            $through,
            $country,
            $state,
            $county,
            $city,
            $postalCode,
            $sourceNote,
            $routingMetadata,
            $actor,
        ): OrganizationTaxRate {
            $this->rates->assertNoOverlap($organization->id, $jurisdictionCode, $from, $through);

            $rate = OrganizationTaxRate::query()->create([
                'parent_account_id' => $organization->parent_account_id,
                'organization_id' => $organization->id,
                'country' => $this->normalizeCountry($country),
                'state' => $this->normalizeRegion($state),
                'county' => $county,
                'city' => $city,
                'postal_code' => $postalCode,
                'routing_metadata_json' => $routingMetadata,
                'jurisdiction_code' => $jurisdictionCode,
                'display_name' => $displayName,
                'rate_ppm' => $ratePpm,
                'effective_from' => $from,
                'effective_through' => $through,
                'is_active' => true,
                'source_note' => $sourceNote,
            ]);

            $this->audit($rate, 'crm.tax_rate.created', null, $this->payload($rate), $actor);

            return $rate;
        });
    }

    /**
     * Edit only what cannot rewrite a past calculation: the label, the provenance
     * note, routing hints, and the date the rate stops applying.
     *
     * @param  array{
     *     display_name?: string,
     *     source_note?: string|null,
     *     routing_metadata_json?: array<string, mixed>|null,
     *     effective_through?: CarbonInterface|string|null
     * }  $data
     */
    public function update(
        OrganizationTaxRate $rate,
        array $data,
        ?User $actor = null,
    ): OrganizationTaxRate {
        $unknown = array_diff(array_keys($data), self::EDITABLE_FIELDS);

        if ($unknown !== []) {
            throw new InvalidTaxConfigurationException(
                'A configured rate cannot change '.implode(', ', $unknown)
                .'; supersede the rate instead so history stays explainable.'
            );
        }

        return DB::transaction(function () use ($rate, $data, $actor): OrganizationTaxRate {
            $locked = $this->lock($rate);
            $before = $this->payload($locked);

            if (array_key_exists('display_name', $data)) {
                $locked->display_name = $this->requireText((string) $data['display_name'], 'Jurisdiction display name');
            }

            if (array_key_exists('source_note', $data)) {
                $note = $data['source_note'];
                $locked->source_note = $note === null ? null : trim((string) $note);
            }

            if (array_key_exists('routing_metadata_json', $data)) {
                $locked->routing_metadata_json = $data['routing_metadata_json'];
            }

            if (array_key_exists('effective_through', $data)) {
                $through = $data['effective_through'];
                $throughDate = $through === null ? null : $this->toDate($through);

                $this->rates->assertNoOverlap(
                    $locked->organization_id,
                    $locked->jurisdiction_code,
                    $locked->effective_from->toDateString(),
                    $throughDate?->toDateString(),
                    $locked->id,
                );

                $locked->effective_through = $throughDate;
            }

            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->save();

            $this->audit($locked, 'crm.tax_rate.updated', $before, $this->payload($locked), $actor);

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Close the current row the day before the new rate starts and open a new row
     * for the same jurisdiction, so both the old and the new rate stay readable.
     *
     * @param  string  $newRatePercent  decimal percentage string, e.g. "8.5" for 8.5%
     */
    public function supersede(
        OrganizationTaxRate $rate,
        string $newRatePercent,
        CarbonInterface|string $effectiveFrom,
        ?string $sourceNote = null,
        ?User $actor = null,
    ): OrganizationTaxRate {
        $ratePpm = $this->toRatePartsPerMillion($newRatePercent);
        $from = $this->toDate($effectiveFrom);

        return DB::transaction(function () use ($rate, $ratePpm, $from, $sourceNote, $actor): OrganizationTaxRate {
            $locked = $this->lock($rate);

            if ($from->lte($locked->effective_from->toDateString())) {
                throw new InvalidTaxConfigurationException(
                    'A superseding rate must take effect after the rate it replaces.'
                );
            }

            if ($locked->effective_through !== null
                && $locked->effective_through->toDateString() < $from->toDateString()) {
                throw new InvalidTaxConfigurationException(
                    'The rate being superseded already ended before the new effective date.'
                );
            }

            $previousPayload = $this->payload($locked);

            $locked->effective_through = $from->copy()->subDay()->startOfDay();
            $locked->save();

            $replacement = OrganizationTaxRate::query()->create([
                'parent_account_id' => $locked->parent_account_id,
                'organization_id' => $locked->organization_id,
                'country' => $locked->country,
                'state' => $locked->state,
                'county' => $locked->county,
                'city' => $locked->city,
                'postal_code' => $locked->postal_code,
                'routing_metadata_json' => $locked->routing_metadata_json,
                'jurisdiction_code' => $locked->jurisdiction_code,
                'display_name' => $locked->display_name,
                'rate_ppm' => $ratePpm,
                'effective_from' => $from->toDateString(),
                'effective_through' => null,
                'is_active' => true,
                'source_note' => $sourceNote ?? $locked->source_note,
            ]);

            $this->audit(
                $replacement,
                'crm.tax_rate.superseded',
                $previousPayload,
                $this->payload($replacement),
                $actor,
            );

            return $replacement;
        });
    }

    /**
     * Stop a rate from being selected without deleting it; existing calculations
     * keep pointing at the snapshot they were made with.
     */
    public function deactivate(OrganizationTaxRate $rate, ?User $actor = null): OrganizationTaxRate
    {
        return DB::transaction(function () use ($rate, $actor): OrganizationTaxRate {
            $locked = $this->lock($rate);

            if (! $locked->is_active) {
                return $locked;
            }

            $before = $this->payload($locked);
            $locked->is_active = false;
            $locked->save();

            $this->audit($locked, 'crm.tax_rate.deactivated', $before, $this->payload($locked), $actor);

            return $locked->fresh() ?? $locked;
        });
    }

    public function activate(OrganizationTaxRate $rate, ?User $actor = null): OrganizationTaxRate
    {
        return DB::transaction(function () use ($rate, $actor): OrganizationTaxRate {
            $locked = $this->lock($rate);

            if ($locked->is_active) {
                return $locked;
            }

            $this->rates->assertNoOverlap(
                $locked->organization_id,
                $locked->jurisdiction_code,
                $locked->effective_from->toDateString(),
                $locked->effective_through?->toDateString(),
                $locked->id,
            );

            $before = $this->payload($locked);
            $locked->is_active = true;
            $locked->save();

            $this->audit($locked, 'crm.tax_rate.activated', $before, $this->payload($locked), $actor);

            return $locked->fresh() ?? $locked;
        });
    }

    private function lock(OrganizationTaxRate $rate): OrganizationTaxRate
    {
        /** @var OrganizationTaxRate $locked */
        $locked = OrganizationTaxRate::query()
            ->whereKey($rate->id)
            ->lockForUpdate()
            ->firstOrFail();

        return $locked;
    }

    private function toRatePartsPerMillion(string $ratePercent): int
    {
        try {
            $ppm = Money::percentToRatePartsPerMillion(trim($ratePercent));
        } catch (InvalidArgumentException $exception) {
            throw new InvalidTaxConfigurationException(
                'Tax rate percentage must be a non-negative decimal string.',
                0,
                $exception,
            );
        }

        if ($ppm > Money::RATE_PARTS_PER_MILLION) {
            throw new InvalidTaxConfigurationException('Tax rate cannot exceed 100%.');
        }

        return $ppm;
    }

    private function toDate(CarbonInterface|string $value): Carbon
    {
        return $value instanceof CarbonInterface
            ? Carbon::parse($value->toDateString())
            : Carbon::parse($value)->startOfDay();
    }

    private function toDateString(CarbonInterface|string $value): string
    {
        return $this->toDate($value)->toDateString();
    }

    private function requireText(string $value, string $label): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidTaxConfigurationException("{$label} is required.");
        }

        return $trimmed;
    }

    private function normalizeCountry(string $country): string
    {
        $normalized = strtoupper(trim($country));

        if (strlen($normalized) !== 2) {
            throw new InvalidTaxConfigurationException('Country must be a two-letter code.');
        }

        return $normalized;
    }

    private function normalizeRegion(?string $region): ?string
    {
        if ($region === null) {
            return null;
        }

        $normalized = strtoupper(trim($region));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(OrganizationTaxRate $rate): array
    {
        return [
            'id' => $rate->id,
            'jurisdiction_code' => $rate->jurisdiction_code,
            'display_name' => $rate->display_name,
            'country' => $rate->country,
            'state' => $rate->state,
            'rate_ppm' => $rate->rate_ppm,
            'effective_from' => $rate->effective_from->toDateString(),
            'effective_through' => $rate->effective_through?->toDateString(),
            'is_active' => $rate->is_active,
            'source_note' => $rate->source_note,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(
        OrganizationTaxRate $rate,
        string $action,
        ?array $before,
        array $after,
        ?User $actor,
    ): void {
        $this->auditor->append(
            parentAccount: ParentAccount::query()->findOrFail($rate->parent_account_id),
            action: $action,
            subjectType: OrganizationTaxRate::class,
            subjectId: $rate->id,
            organization: Organization::query()->findOrFail($rate->organization_id),
            actor: $actor,
            before: $before,
            after: $after,
            correlationId: (string) Str::uuid(),
        );
    }
}
