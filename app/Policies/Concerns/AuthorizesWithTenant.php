<?php

namespace App\Policies\Concerns;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\OrganizationCompany;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Tenancy\TenantContext;

trait AuthorizesWithTenant
{
    protected function tenant(): ?TenantContext
    {
        return TenantContext::getOptional();
    }

    protected function inTenant(): bool
    {
        return TenantContext::has();
    }

    protected function companyAssociatedWithCurrentOrganization(Company $company): bool
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return false;
        }

        if ($company->parent_account_id !== null && $company->parent_account_id !== $tenant->parentAccountId) {
            return false;
        }

        return OrganizationCompany::query()
            ->where('organization_id', $tenant->organizationId)
            ->where('company_id', $company->id)
            ->exists();
    }

    protected function contactVisibleInCurrentOrganization(Contact $contact): bool
    {
        $contact->loadMissing('company');

        return $this->companyAssociatedWithCurrentOrganization($contact->company);
    }

    protected function dealInCurrentOrganization(Deal $deal): bool
    {
        $tenant = $this->tenant();

        return $tenant !== null && $deal->organization_id === $tenant->organizationId;
    }

    protected function productInCurrentParent(Product $product): bool
    {
        $tenant = $this->tenant();

        return $tenant !== null && $product->parent_account_id === $tenant->parentAccountId;
    }

    protected function vendorInCurrentParent(Vendor $vendor): bool
    {
        $tenant = $this->tenant();

        return $tenant !== null && $vendor->parent_account_id === $tenant->parentAccountId;
    }

    protected function categoryInCurrentParent(ProductCategory $category): bool
    {
        $tenant = $this->tenant();

        return $tenant !== null && $category->parent_account_id === $tenant->parentAccountId;
    }

    protected function ownsDeal(User $user, Deal $deal): bool
    {
        return $deal->owner_id === $user->id;
    }
}
