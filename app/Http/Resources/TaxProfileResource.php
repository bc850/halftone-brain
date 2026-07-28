<?php

namespace App\Http\Resources;

use App\Models\OrganizationTaxProfile;

/**
 * An organization's single tax configuration row.
 *
 * `configuration_version` is surfaced so a reader can tell whether a stored
 * calculation was made under the configuration currently on screen.
 */
final class TaxProfileResource
{
    /**
     * @return array<string, mixed>|null
     */
    public static function make(?OrganizationTaxProfile $profile): ?array
    {
        if ($profile === null) {
            return null;
        }

        return [
            'id' => $profile->id,
            'default_country' => $profile->default_country,
            'default_state' => $profile->default_state,
            'sourcing_strategy' => $profile->sourcing_strategy->value,
            'sourcing_strategy_label' => $profile->sourcing_strategy->label(),
            'registration_reference' => $profile->registration_reference,
            'tax_calculation_enabled' => $profile->tax_calculation_enabled,
            'is_active' => $profile->is_active,
            'configuration_version' => $profile->configuration_version,
            'updated_at' => $profile->updated_at?->toIso8601String(),
        ];
    }
}
