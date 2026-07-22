<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Vendor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

trait ScopesQueriesToTenant
{
    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    protected function scopeCompaniesForRequest(Builder $query): Builder
    {
        if (! TenantContext::has()) {
            return $query;
        }

        $tenant = TenantContext::get();

        return $query
            ->where(function (Builder $inner) use ($tenant): void {
                $inner->whereNull('parent_account_id')
                    ->orWhere('parent_account_id', $tenant->parentAccountId);
            })
            ->whereHas('organizationCompanies', function (Builder $assoc) use ($tenant): void {
                $assoc->where('organization_id', $tenant->organizationId);
            });
    }

    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    protected function scopeContactsForRequest(Builder $query): Builder
    {
        if (! TenantContext::has()) {
            return $query;
        }

        $tenant = TenantContext::get();

        return $query->whereHas('company', function (Builder $company) use ($tenant): void {
            $company->where(function (Builder $inner) use ($tenant): void {
                $inner->whereNull('parent_account_id')
                    ->orWhere('parent_account_id', $tenant->parentAccountId);
            })->whereHas('organizationCompanies', function (Builder $assoc) use ($tenant): void {
                $assoc->where('organization_id', $tenant->organizationId);
            });
        });
    }

    /**
     * @param  Builder<Deal>  $query
     * @return Builder<Deal>
     */
    protected function scopeDealsForRequest(Builder $query): Builder
    {
        if (! TenantContext::has()) {
            return $query;
        }

        return $query->where('organization_id', TenantContext::get()->organizationId);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    protected function scopeProductsForRequest(Builder $query): Builder
    {
        if (! TenantContext::has()) {
            return $query;
        }

        $tenant = TenantContext::get();

        return $query->where(function (Builder $inner) use ($tenant): void {
            $inner->whereNull('parent_account_id')
                ->orWhere('parent_account_id', $tenant->parentAccountId);
        });
    }

    /**
     * @param  Builder<Vendor>  $query
     * @return Builder<Vendor>
     */
    protected function scopeVendorsForRequest(Builder $query): Builder
    {
        if (! TenantContext::has()) {
            return $query;
        }

        $tenant = TenantContext::get();

        return $query->where(function (Builder $inner) use ($tenant): void {
            $inner->whereNull('parent_account_id')
                ->orWhere('parent_account_id', $tenant->parentAccountId);
        });
    }

    /**
     * @param  Builder<ProductCategory>  $query
     * @return Builder<ProductCategory>
     */
    protected function scopeCategoriesForRequest(Builder $query): Builder
    {
        if (! TenantContext::has()) {
            return $query;
        }

        $tenant = TenantContext::get();

        return $query->where(function (Builder $inner) use ($tenant): void {
            $inner->whereNull('parent_account_id')
                ->orWhere('parent_account_id', $tenant->parentAccountId);
        });
    }
}
