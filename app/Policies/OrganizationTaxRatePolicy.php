<?php

namespace App\Policies;

use App\Models\OrganizationTaxRate;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTaxConfiguration;

class OrganizationTaxRatePolicy
{
    use AuthorizesTaxConfiguration;

    public function viewAny(User $user): bool
    {
        return $this->canViewTaxConfiguration();
    }

    public function view(User $user, OrganizationTaxRate $rate): bool
    {
        return $this->canViewTaxConfiguration()
            && $this->belongsToCurrentOrganization($rate->organization_id, $rate->parent_account_id);
    }

    public function create(User $user): bool
    {
        return $this->canManageTaxConfiguration();
    }

    public function update(User $user, OrganizationTaxRate $rate): bool
    {
        return $this->canManageTaxConfiguration()
            && $this->belongsToCurrentOrganization($rate->organization_id, $rate->parent_account_id);
    }

    /**
     * Retiring a rate is a configuration change; rates are never deleted.
     */
    public function deactivate(User $user, OrganizationTaxRate $rate): bool
    {
        return $this->update($user, $rate);
    }
}
