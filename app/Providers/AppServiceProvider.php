<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\OrganizationCompany;
use App\Models\OrganizationProduct;
use App\Models\OrganizationProductUnitConversion;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Team;
use App\Models\Vendor;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureTenantRouteBindings();

        $this->app->terminating(function (): void {
            TenantContext::clear();
        });
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    protected function configureTenantRouteBindings(): void
    {
        Route::bind('organization', function (string $value): Organization {
            if (TenantContext::has()) {
                $organization = TenantContext::get()->organization;

                if ($organization->slug !== $value) {
                    abort(404);
                }

                return $organization;
            }

            return Organization::query()
                ->where('slug', $value)
                ->where('is_active', true)
                ->firstOrFail();
        });

        Route::bind('company', function (string $value): Company {
            if (TenantContext::has()) {
                $tenant = TenantContext::get();

                return Company::query()
                    ->whereKey($value)
                    ->where(function ($query) use ($tenant): void {
                        $query->whereNull('parent_account_id')
                            ->orWhere('parent_account_id', $tenant->parentAccountId);
                    })
                    ->whereHas('organizationCompanies', function ($query) use ($tenant): void {
                        $query->where('organization_id', $tenant->organizationId);
                    })
                    ->firstOrFail();
            }

            return Company::query()->whereKey($value)->firstOrFail();
        });

        Route::bind('contact', function (string $value): Contact {
            if (TenantContext::has()) {
                $tenant = TenantContext::get();

                return Contact::query()
                    ->whereKey($value)
                    ->whereHas('company', function ($query) use ($tenant): void {
                        $query->where(function ($inner) use ($tenant): void {
                            $inner->whereNull('parent_account_id')
                                ->orWhere('parent_account_id', $tenant->parentAccountId);
                        })->whereHas('organizationCompanies', function ($assoc) use ($tenant): void {
                            $assoc->where('organization_id', $tenant->organizationId);
                        });
                    })
                    ->firstOrFail();
            }

            return Contact::query()->whereKey($value)->firstOrFail();
        });

        Route::bind('deal', function (string $value): Deal {
            if (TenantContext::has()) {
                return Deal::query()
                    ->whereKey($value)
                    ->where('organization_id', TenantContext::get()->organizationId)
                    ->firstOrFail();
            }

            return Deal::query()->whereKey($value)->firstOrFail();
        });

        Route::bind('team', function (string $value): Team {
            if (TenantContext::has()) {
                return Team::query()
                    ->whereKey($value)
                    ->where('organization_id', TenantContext::get()->organizationId)
                    ->firstOrFail();
            }

            return Team::query()->whereKey($value)->firstOrFail();
        });

        Route::bind('organization_company', function (string $value): OrganizationCompany {
            if (TenantContext::has()) {
                return OrganizationCompany::query()
                    ->whereKey($value)
                    ->where('organization_id', TenantContext::get()->organizationId)
                    ->firstOrFail();
            }

            return OrganizationCompany::query()->whereKey($value)->firstOrFail();
        });

        Route::bind('product', function (string $value): Product {
            if (TenantContext::has()) {
                $tenant = TenantContext::get();

                return Product::query()
                    ->whereKey($value)
                    ->where(function ($query) use ($tenant): void {
                        $query->whereNull('parent_account_id')
                            ->orWhere('parent_account_id', $tenant->parentAccountId);
                    })
                    ->firstOrFail();
            }

            return Product::query()->whereKey($value)->firstOrFail();
        });

        Route::bind('organizationProduct', function (string $value): OrganizationProduct {
            if (! TenantContext::has()) {
                abort(404);
            }

            $tenant = TenantContext::get();

            return OrganizationProduct::query()
                ->whereKey($value)
                ->where('organization_id', $tenant->organizationId)
                ->where('parent_account_id', $tenant->parentAccountId)
                ->firstOrFail();
        });

        Route::bind('unitConversion', function (string $value, \Illuminate\Routing\Route $route): OrganizationProductUnitConversion {
            if (! TenantContext::has()) {
                abort(404);
            }

            $tenant = TenantContext::get();

            /** @var OrganizationProduct $organizationProduct */
            $organizationProduct = $route->parameter('organizationProduct');

            return OrganizationProductUnitConversion::query()
                ->whereKey($value)
                ->where('organization_product_id', $organizationProduct->id)
                ->where('organization_id', $tenant->organizationId)
                ->where('parent_account_id', $tenant->parentAccountId)
                ->firstOrFail();
        });

        Route::bind('vendor', function (string $value): Vendor {
            if (TenantContext::has()) {
                $tenant = TenantContext::get();

                return Vendor::query()
                    ->whereKey($value)
                    ->where(function ($query) use ($tenant): void {
                        $query->whereNull('parent_account_id')
                            ->orWhere('parent_account_id', $tenant->parentAccountId);
                    })
                    ->firstOrFail();
            }

            return Vendor::query()->whereKey($value)->firstOrFail();
        });

        Route::bind('category', function (string $value): ProductCategory {
            if (TenantContext::has()) {
                $tenant = TenantContext::get();

                return ProductCategory::query()
                    ->whereKey($value)
                    ->where(function ($query) use ($tenant): void {
                        $query->whereNull('parent_account_id')
                            ->orWhere('parent_account_id', $tenant->parentAccountId);
                    })
                    ->firstOrFail();
            }

            return ProductCategory::query()->whereKey($value)->firstOrFail();
        });
    }
}
