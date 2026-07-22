<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vendor;
use App\Policies\Concerns\AuthorizesWithTenant;

class VendorPolicy
{
    use AuthorizesWithTenant;

    public function viewAny(User $user): bool
    {
        if ($this->inTenant()) {
            return $this->tenant()->canOrg('catalog.vendor.view');
        }

        return true;
    }

    public function view(User $user, Vendor $vendor): bool
    {
        if ($this->inTenant()) {
            return $this->vendorInCurrentParent($vendor)
                && $this->tenant()->canOrg('catalog.vendor.view');
        }

        return true;
    }

    public function create(User $user): bool
    {
        if ($this->inTenant()) {
            return $this->tenant()->canOrg('catalog.vendor.create')
                && $this->tenant()->canParent('parent.catalog.vendor.manage');
        }

        return $user->isAdmin();
    }

    public function update(User $user, Vendor $vendor): bool
    {
        if ($this->inTenant()) {
            return $this->vendorInCurrentParent($vendor)
                && $this->tenant()->canOrg('catalog.vendor.update')
                && $this->tenant()->canParent('parent.catalog.vendor.manage');
        }

        return $user->isAdmin();
    }

    public function delete(User $user, Vendor $vendor): bool
    {
        if ($this->inTenant()) {
            return $this->vendorInCurrentParent($vendor)
                && $this->tenant()->canOrg('catalog.vendor.delete')
                && $this->tenant()->canParent('parent.catalog.vendor.manage');
        }

        return $user->isAdmin();
    }

    public function viewDetails(User $user, Vendor $vendor): bool
    {
        if ($this->inTenant()) {
            return $this->vendorInCurrentParent($vendor)
                && $this->tenant()->canParent('parent.catalog.vendor.manage');
        }

        return $user->isAdmin();
    }
}
