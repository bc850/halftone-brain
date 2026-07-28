<?php

namespace App\Policies\Concerns;

/**
 * Shared gates for an organization's tax configuration.
 *
 * Anyone who may resolve tax on a quote needs to read the configuration to pick a
 * jurisdiction, so viewing is open to `crm.quote.tax_calculate`. Changing a rate or the
 * profile changes what every future quote is taxed at, so it takes the narrower
 * `crm.quote.tax_override` authority. Neither gate exposes certificate evidence, which
 * is governed separately by `crm.tax_certificate.*`.
 */
trait AuthorizesTaxConfiguration
{
    use AuthorizesWithTenant;

    protected function canViewTaxConfiguration(): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->tenant()->canOrg('crm.quote.tax_calculate')
            || $this->tenant()->canOrg('crm.quote.tax_override');
    }

    protected function canManageTaxConfiguration(): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->tenant()->canOrg('crm.quote.tax_override');
    }

    protected function belongsToCurrentOrganization(int $organizationId, int $parentAccountId): bool
    {
        $tenant = $this->tenant();

        return $tenant !== null
            && $organizationId === $tenant->organizationId
            && $parentAccountId === $tenant->parentAccountId;
    }
}
