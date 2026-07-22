<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithTenant;

class ProductPolicy
{
    use AuthorizesWithTenant;

    public function viewAny(User $user): bool
    {
        if ($this->inTenant()) {
            return $this->tenant()->canOrg('catalog.product.view');
        }

        return true;
    }

    public function view(User $user, Product $product): bool
    {
        if ($this->inTenant()) {
            return $this->productInCurrentParent($product)
                && $this->tenant()->canOrg('catalog.product.view');
        }

        return true;
    }

    public function create(User $user): bool
    {
        if ($this->inTenant()) {
            return $this->tenant()->canOrg('catalog.product.create')
                && $this->tenant()->canParent('parent.catalog.product.manage');
        }

        return $user->isAdmin();
    }

    public function update(User $user, Product $product): bool
    {
        if ($this->inTenant()) {
            return $this->productInCurrentParent($product)
                && $this->tenant()->canOrg('catalog.product.update')
                && $this->tenant()->canParent('parent.catalog.product.manage');
        }

        return $user->isAdmin();
    }

    public function delete(User $user, Product $product): bool
    {
        if ($this->inTenant()) {
            return $this->productInCurrentParent($product)
                && $this->tenant()->canOrg('catalog.product.delete')
                && $this->tenant()->canParent('parent.catalog.product.manage');
        }

        return $user->isAdmin();
    }

    public function viewCost(User $user, Product $product): bool
    {
        if ($this->inTenant()) {
            return $this->productInCurrentParent($product)
                && $this->tenant()->canViewCost();
        }

        return $user->isAdmin();
    }
}
