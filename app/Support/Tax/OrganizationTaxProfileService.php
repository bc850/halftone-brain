<?php

namespace App\Support\Tax;

use App\Enums\TaxSourcingStrategy;
use App\Models\Organization;
use App\Models\OrganizationTaxProfile;
use App\Models\ParentAccount;
use App\Models\User;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes an organization's single tax configuration row.
 *
 * `configuration_version` is the version a calculation can be traced back to, so it
 * moves only when a field that changes how tax is decided changes. Renaming a
 * registration reference does not invalidate a past calculation; switching sourcing
 * strategy, default jurisdiction, or the calculation switch does.
 *
 * Permission checks belong to the caller. Nothing here reads TenantContext.
 */
final class OrganizationTaxProfileService
{
    /**
     * Fields whose change alters how a future calculation resolves and therefore
     * bumps `configuration_version`.
     *
     * @var list<string>
     */
    private const MATERIAL_FIELDS = [
        'default_country',
        'default_state',
        'sourcing_strategy',
        'tax_calculation_enabled',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    private const EDITABLE_FIELDS = [
        'default_country',
        'default_state',
        'sourcing_strategy',
        'registration_reference',
        'registration_metadata_json',
        'tax_calculation_enabled',
        'is_active',
    ];

    public function __construct(private Auditor $auditor) {}

    /**
     * @param  array<string, mixed>|null  $registrationMetadata
     */
    public function create(
        Organization $organization,
        string $defaultCountry = 'US',
        ?string $defaultState = null,
        TaxSourcingStrategy $sourcingStrategy = TaxSourcingStrategy::Delivery,
        ?string $registrationReference = null,
        ?array $registrationMetadata = null,
        bool $taxCalculationEnabled = true,
        ?User $actor = null,
    ): OrganizationTaxProfile {
        return DB::transaction(function () use (
            $organization,
            $defaultCountry,
            $defaultState,
            $sourcingStrategy,
            $registrationReference,
            $registrationMetadata,
            $taxCalculationEnabled,
            $actor,
        ): OrganizationTaxProfile {
            $existing = OrganizationTaxProfile::query()
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new InvalidTaxConfigurationException(
                    "Organization [{$organization->id}] already has a tax profile."
                );
            }

            $profile = OrganizationTaxProfile::query()->create([
                'parent_account_id' => $organization->parent_account_id,
                'organization_id' => $organization->id,
                'default_country' => $this->normalizeCountry($defaultCountry),
                'default_state' => $this->normalizeState($defaultState),
                'sourcing_strategy' => $sourcingStrategy,
                'registration_reference' => $registrationReference,
                'registration_metadata_json' => $registrationMetadata,
                'tax_calculation_enabled' => $taxCalculationEnabled,
                'is_active' => true,
                'configuration_version' => 1,
            ]);

            $this->audit($profile, 'crm.tax_profile.created', null, $this->payload($profile), $actor);

            return $profile;
        });
    }

    /**
     * @param  array{
     *     default_country?: string,
     *     default_state?: string|null,
     *     sourcing_strategy?: TaxSourcingStrategy|string,
     *     registration_reference?: string|null,
     *     registration_metadata_json?: array<string, mixed>|null,
     *     tax_calculation_enabled?: bool,
     *     is_active?: bool
     * }  $data
     */
    public function update(
        OrganizationTaxProfile $profile,
        array $data,
        ?User $actor = null,
    ): OrganizationTaxProfile {
        return DB::transaction(function () use ($profile, $data, $actor): OrganizationTaxProfile {
            $unknown = array_diff(array_keys($data), self::EDITABLE_FIELDS);
            if ($unknown !== []) {
                throw new InvalidTaxConfigurationException(
                    'Unsupported tax profile fields: '.implode(', ', $unknown).'.'
                );
            }

            /** @var OrganizationTaxProfile $locked */
            $locked = OrganizationTaxProfile::query()
                ->whereKey($profile->id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = $this->payload($locked);

            if (array_key_exists('default_country', $data)) {
                $locked->default_country = $this->normalizeCountry((string) $data['default_country']);
            }

            if (array_key_exists('default_state', $data)) {
                $state = $data['default_state'];
                $locked->default_state = $this->normalizeState($state === null ? null : (string) $state);
            }

            if (array_key_exists('sourcing_strategy', $data)) {
                $strategy = $data['sourcing_strategy'];
                $locked->sourcing_strategy = $strategy instanceof TaxSourcingStrategy
                    ? $strategy
                    : TaxSourcingStrategy::from((string) $strategy);
            }

            if (array_key_exists('registration_reference', $data)) {
                $reference = $data['registration_reference'];
                $locked->registration_reference = $reference === null ? null : trim((string) $reference);
            }

            if (array_key_exists('registration_metadata_json', $data)) {
                $locked->registration_metadata_json = $data['registration_metadata_json'];
            }

            if (array_key_exists('tax_calculation_enabled', $data)) {
                $locked->tax_calculation_enabled = (bool) $data['tax_calculation_enabled'];
            }

            if (array_key_exists('is_active', $data)) {
                $locked->is_active = (bool) $data['is_active'];
            }

            $materialChange = array_intersect(array_keys($locked->getDirty()), self::MATERIAL_FIELDS) !== [];

            if ($materialChange) {
                $locked->configuration_version = $locked->configuration_version + 1;
            }

            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->save();

            $this->audit($locked, 'crm.tax_profile.updated', $before, $this->payload($locked), $actor);

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Turn calculation on or off without discarding the configured jurisdictions.
     */
    public function setTaxCalculationEnabled(
        OrganizationTaxProfile $profile,
        bool $enabled,
        ?User $actor = null,
    ): OrganizationTaxProfile {
        return $this->update($profile, ['tax_calculation_enabled' => $enabled], $actor);
    }

    /**
     * Retire or restore the whole profile. An inactive profile leaves every
     * calculation unresolved rather than silently untaxed.
     */
    public function setActive(
        OrganizationTaxProfile $profile,
        bool $active,
        ?User $actor = null,
    ): OrganizationTaxProfile {
        return $this->update($profile, ['is_active' => $active], $actor);
    }

    private function normalizeCountry(string $country): string
    {
        $normalized = strtoupper(trim($country));

        if (strlen($normalized) !== 2) {
            throw new InvalidTaxConfigurationException('Country must be a two-letter code.');
        }

        return $normalized;
    }

    private function normalizeState(?string $state): ?string
    {
        if ($state === null) {
            return null;
        }

        $normalized = strtoupper(trim($state));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(OrganizationTaxProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'default_country' => $profile->default_country,
            'default_state' => $profile->default_state,
            'sourcing_strategy' => $profile->sourcing_strategy->value,
            'registration_reference' => $profile->registration_reference,
            'tax_calculation_enabled' => $profile->tax_calculation_enabled,
            'is_active' => $profile->is_active,
            'configuration_version' => $profile->configuration_version,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(
        OrganizationTaxProfile $profile,
        string $action,
        ?array $before,
        array $after,
        ?User $actor,
    ): void {
        $this->auditor->append(
            parentAccount: ParentAccount::query()->findOrFail($profile->parent_account_id),
            action: $action,
            subjectType: OrganizationTaxProfile::class,
            subjectId: $profile->id,
            organization: Organization::query()->findOrFail($profile->organization_id),
            actor: $actor,
            before: $before,
            after: $after,
            correlationId: (string) Str::uuid(),
        );
    }
}
