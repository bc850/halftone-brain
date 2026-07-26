<?php

namespace App\Policies;

use App\Models\OrganizationProduct;
use App\Models\OrganizationProductSource;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithTenant;

/**
 * Organization vendor sources.
 *
 * Attach / activate / deactivate → catalog.org_product.manage
 * Package price + preferred selection → manage_pricing + cost view
 * Cost/history visibility → cost view
 */
class OrganizationProductSourcePolicy
{
    use AuthorizesWithTenant;

    public function viewAny(User $user): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->tenant()->canOrg('catalog.product.view');
    }

    public function view(User $user, OrganizationProductSource $source): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->sourceInCurrentOrganization($source)
            && $this->tenant()->canOrg('catalog.product.view');
    }

    public function attach(User $user, OrganizationProduct $organizationProduct): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->organizationProductInCurrentOrganization($organizationProduct)
            && $this->tenant()->canOrg('catalog.org_product.manage');
    }

    public function activate(User $user, OrganizationProductSource $source): bool
    {
        return $this->manageLifecycle($user, $source);
    }

    public function deactivate(User $user, OrganizationProductSource $source): bool
    {
        return $this->manageLifecycle($user, $source);
    }

    public function updatePrice(User $user, OrganizationProductSource $source): bool
    {
        return $this->managePricing($user, $source);
    }

    public function selectPreferred(User $user, OrganizationProductSource $source): bool
    {
        return $this->managePricing($user, $source);
    }

    public function clearPreferred(User $user, OrganizationProduct $organizationProduct): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->organizationProductInCurrentOrganization($organizationProduct)
            && $this->tenant()->canOrg('catalog.org_product.manage_pricing')
            && $this->tenant()->canViewCost();
    }

    public function viewCost(User $user, OrganizationProductSource $source): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->sourceInCurrentOrganization($source)
            && $this->tenant()->canViewCost();
    }

    private function manageLifecycle(User $user, OrganizationProductSource $source): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->sourceInCurrentOrganization($source)
            && $this->tenant()->canOrg('catalog.org_product.manage');
    }

    private function managePricing(User $user, OrganizationProductSource $source): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->sourceInCurrentOrganization($source)
            && $this->tenant()->canOrg('catalog.org_product.manage_pricing')
            && $this->tenant()->canViewCost();
    }

    private function sourceInCurrentOrganization(OrganizationProductSource $source): bool
    {
        $tenant = $this->tenant();

        return $tenant !== null
            && $source->organization_id === $tenant->organizationId
            && $source->parent_account_id === $tenant->parentAccountId;
    }

    private function organizationProductInCurrentOrganization(OrganizationProduct $organizationProduct): bool
    {
        $tenant = $this->tenant();

        return $tenant !== null
            && $organizationProduct->organization_id === $tenant->organizationId
            && $organizationProduct->parent_account_id === $tenant->parentAccountId;
    }
}
