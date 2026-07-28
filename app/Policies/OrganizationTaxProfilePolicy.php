<?php

namespace App\Policies;

use App\Models\OrganizationTaxProfile;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTaxConfiguration;

class OrganizationTaxProfilePolicy
{
    use AuthorizesTaxConfiguration;

    public function viewAny(User $user): bool
    {
        return $this->canViewTaxConfiguration();
    }

    public function view(User $user, OrganizationTaxProfile $profile): bool
    {
        return $this->canViewTaxConfiguration()
            && $this->belongsToCurrentOrganization($profile->organization_id, $profile->parent_account_id);
    }

    public function create(User $user): bool
    {
        return $this->canManageTaxConfiguration();
    }

    public function update(User $user, OrganizationTaxProfile $profile): bool
    {
        return $this->canManageTaxConfiguration()
            && $this->belongsToCurrentOrganization($profile->organization_id, $profile->parent_account_id);
    }
}
