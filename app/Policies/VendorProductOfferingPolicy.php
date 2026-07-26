<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VendorProductOffering;
use App\Policies\Concerns\AuthorizesWithTenant;

/**
 * Parent-scoped vendor offerings. Mutations require parent product catalog authority.
 * Organization-only catalog administration is not sufficient.
 */
class VendorProductOfferingPolicy
{
    use AuthorizesWithTenant;

    public function viewAny(User $user): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->tenant()->canOrg('catalog.product.view')
            || $this->tenant()->canOrg('catalog.vendor.view');
    }

    public function view(User $user, VendorProductOffering $offering): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->offeringInCurrentParent($offering)
            && (
                $this->tenant()->canOrg('catalog.product.view')
                || $this->tenant()->canOrg('catalog.vendor.view')
            );
    }

    public function create(User $user): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->tenant()->canParent('parent.catalog.product.manage');
    }

    public function update(User $user, VendorProductOffering $offering): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->offeringInCurrentParent($offering)
            && $this->tenant()->canParent('parent.catalog.product.manage');
    }

    public function discontinue(User $user, VendorProductOffering $offering): bool
    {
        return $this->update($user, $offering);
    }

    public function reactivate(User $user, VendorProductOffering $offering): bool
    {
        return $this->update($user, $offering);
    }

    private function offeringInCurrentParent(VendorProductOffering $offering): bool
    {
        $tenant = $this->tenant();

        return $tenant !== null && $offering->parent_account_id === $tenant->parentAccountId;
    }
}
