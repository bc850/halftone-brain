<?php

namespace App\Policies;

use App\Models\ProductCategory;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithTenant;

class ProductCategoryPolicy
{
    use AuthorizesWithTenant;

    public function viewAny(User $user): bool
    {
        if ($this->inTenant()) {
            return $this->tenant()->canOrg('catalog.category.view');
        }

        return true;
    }

    public function view(User $user, ProductCategory $productCategory): bool
    {
        if ($this->inTenant()) {
            return $this->categoryInCurrentParent($productCategory)
                && $this->tenant()->canOrg('catalog.category.view');
        }

        return true;
    }

    public function create(User $user): bool
    {
        if ($this->inTenant()) {
            return $this->tenant()->canOrg('catalog.category.create')
                && $this->tenant()->canParent('parent.catalog.category.manage');
        }

        return $user->isAdmin();
    }

    public function update(User $user, ProductCategory $productCategory): bool
    {
        if ($this->inTenant()) {
            return $this->categoryInCurrentParent($productCategory)
                && $this->tenant()->canOrg('catalog.category.update')
                && $this->tenant()->canParent('parent.catalog.category.manage');
        }

        return $user->isAdmin();
    }

    public function delete(User $user, ProductCategory $productCategory): bool
    {
        if ($this->inTenant()) {
            return $this->categoryInCurrentParent($productCategory)
                && $this->tenant()->canOrg('catalog.category.delete')
                && $this->tenant()->canParent('parent.catalog.category.manage');
        }

        return $user->isAdmin();
    }
}
