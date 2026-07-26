<?php

namespace App\Policies;

use App\Models\OrganizationProduct;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithTenant;

class OrganizationProductPolicy
{
    use AuthorizesWithTenant;

    public function viewAny(User $user): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->tenant()->canOrg('catalog.product.view');
    }

    public function view(User $user, OrganizationProduct $organizationProduct): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->organizationProductInCurrentOrganization($organizationProduct)
            && $this->tenant()->canOrg('catalog.product.view');
    }

    public function create(User $user): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->tenant()->canParent('parent.catalog.product.manage')
            && $this->tenant()->canOrg('catalog.org_product.manage')
            && $this->tenant()->canOrg('catalog.org_product.manage_pricing')
            && $this->tenant()->canViewCost();
    }

    public function associate(User $user): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->tenant()->canOrg('catalog.org_product.manage');
    }

    public function updateMaster(User $user, OrganizationProduct $organizationProduct): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->organizationProductInCurrentOrganization($organizationProduct)
            && $this->tenant()->canParent('parent.catalog.product.manage');
    }

    public function updateSettings(User $user, OrganizationProduct $organizationProduct): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->organizationProductInCurrentOrganization($organizationProduct)
            && $this->tenant()->canOrg('catalog.org_product.manage');
    }

    public function manageComponents(User $user, OrganizationProduct $organizationProduct): bool
    {
        return $this->updateSettings($user, $organizationProduct);
    }

    public function updatePricing(User $user, OrganizationProduct $organizationProduct): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->organizationProductInCurrentOrganization($organizationProduct)
            && $this->tenant()->canOrg('catalog.org_product.manage_pricing')
            && $this->tenant()->canViewCost();
    }

    public function updatePurchaseCost(User $user, OrganizationProduct $organizationProduct): bool
    {
        return $this->updatePricing($user, $organizationProduct);
    }

    public function previewPricing(User $user): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->tenant()->canOrg('catalog.org_product.manage_pricing')
            && $this->tenant()->canViewCost();
    }

    public function archive(User $user, OrganizationProduct $organizationProduct): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->organizationProductInCurrentOrganization($organizationProduct)
            && $this->tenant()->canOrg('catalog.org_product.archive');
    }

    public function viewCost(User $user, OrganizationProduct $organizationProduct): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->organizationProductInCurrentOrganization($organizationProduct)
            && $this->tenant()->canViewCost();
    }

    private function organizationProductInCurrentOrganization(OrganizationProduct $organizationProduct): bool
    {
        $tenant = $this->tenant();

        return $tenant !== null
            && $organizationProduct->organization_id === $tenant->organizationId
            && $organizationProduct->parent_account_id === $tenant->parentAccountId;
    }
}
