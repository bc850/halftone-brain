<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithTenant;

class CompanyPolicy
{
    use AuthorizesWithTenant;

    public function viewAny(User $user): bool
    {
        if ($this->inTenant()) {
            return $this->tenant()->canOrg('crm.company.view')
                || $this->tenant()->canOrg('crm.company.view_all');
        }

        return true;
    }

    public function view(User $user, Company $company): bool
    {
        if ($this->inTenant()) {
            if (! $this->companyAssociatedWithCurrentOrganization($company)) {
                return false;
            }

            if ($this->tenant()->canOrg('crm.company.view_all')) {
                return true;
            }

            return $this->tenant()->canOrg('crm.company.view')
                && $company->owner_id === $user->id;
        }

        return $user->canSeeEveryone() || $company->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        if ($this->inTenant()) {
            return $this->tenant()->canOrg('crm.company.create');
        }

        return $user->isAdmin() || $user->isSalesman();
    }

    public function update(User $user, Company $company): bool
    {
        if ($this->inTenant()) {
            // Shared company identity requires parent permission.
            return $this->companyAssociatedWithCurrentOrganization($company)
                && $this->tenant()->canParent('parent.company.update');
        }

        return $user->isAdmin() || $company->owner_id === $user->id;
    }

    public function delete(User $user, Company $company): bool
    {
        if ($this->inTenant()) {
            return $this->companyAssociatedWithCurrentOrganization($company)
                && $this->tenant()->canOrg('crm.company.delete')
                && $this->tenant()->canParent('parent.company.update');
        }

        return $user->isAdmin() || $company->owner_id === $user->id;
    }
}
