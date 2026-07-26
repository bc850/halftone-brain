<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\OrganizationProductController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\VendorProductOfferingController;
use App\Http\Controllers\VisibilityPreferenceController;
use App\Http\Middleware\EnforceLegacyTenantBoundary;
use App\Http\Middleware\ResolveTenantContextFromRoute;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::patch('preferences/visibility', [VisibilityPreferenceController::class, 'update'])
        ->name('preferences.visibility');

    /*
     | Legacy CRM/catalog routes remain registered for compatibility.
     | GET navigations redirect into /o/{organization}/…;
     | mutations fail closed with 409 before controllers run.
     */
    Route::middleware([EnforceLegacyTenantBoundary::class])->group(function () {
        Route::inertia('dashboard', 'Dashboard')->name('dashboard');

        Route::resource('companies', CompanyController::class);
        Route::resource('contacts', ContactController::class);
        Route::resource('deals', DealController::class);
        Route::patch('deals/{deal}/stage', [DealController::class, 'updateStage'])->name('deals.stage');

        Route::resource('vendors', VendorController::class);
        Route::resource('categories', ProductCategoryController::class)
            ->parameters(['categories' => 'category']);
        Route::resource('products', ProductController::class);
    });
});

Route::middleware(['auth', 'verified', ResolveTenantContextFromRoute::class])
    ->prefix('o/{organization}')
    ->name('org.')
    ->group(function () {
        Route::inertia('dashboard', 'Dashboard')->name('dashboard');

        Route::resource('companies', CompanyController::class);
        Route::resource('contacts', ContactController::class);
        Route::resource('deals', DealController::class);
        Route::patch('deals/{deal}/stage', [DealController::class, 'updateStage'])->name('deals.stage');

        Route::resource('vendors', VendorController::class);

        Route::get('vendors/{vendor}/offerings/create', [VendorProductOfferingController::class, 'createForVendor'])
            ->name('vendors.offerings.create');
        Route::post('vendors/{vendor}/offerings', [VendorProductOfferingController::class, 'storeForVendor'])
            ->name('vendors.offerings.store');
        Route::get('vendors/{vendor}/offerings/{vendorProductOffering}', [VendorProductOfferingController::class, 'showForVendor'])
            ->name('vendors.offerings.show');
        Route::get('vendors/{vendor}/offerings/{vendorProductOffering}/edit', [VendorProductOfferingController::class, 'editForVendor'])
            ->name('vendors.offerings.edit');
        Route::patch('vendors/{vendor}/offerings/{vendorProductOffering}', [VendorProductOfferingController::class, 'updateForVendor'])
            ->name('vendors.offerings.update');
        Route::post('vendors/{vendor}/offerings/{vendorProductOffering}/discontinue', [VendorProductOfferingController::class, 'discontinueForVendor'])
            ->name('vendors.offerings.discontinue');
        Route::post('vendors/{vendor}/offerings/{vendorProductOffering}/reactivate', [VendorProductOfferingController::class, 'reactivateForVendor'])
            ->name('vendors.offerings.reactivate');

        Route::resource('categories', ProductCategoryController::class)
            ->parameters(['categories' => 'category']);

        Route::get('products/add-existing', [OrganizationProductController::class, 'createFromMaster'])
            ->name('products.add-existing');
        Route::post('products/associate', [OrganizationProductController::class, 'associate'])
            ->name('products.associate');
        Route::post('products/pricing-preview', [OrganizationProductController::class, 'previewPricing'])
            ->name('products.pricing-preview');

        Route::resource('products', OrganizationProductController::class)
            ->parameters(['products' => 'organizationProduct'])
            ->except(['edit', 'update', 'destroy']);

        Route::get('products/{organizationProduct}/offerings/create', [VendorProductOfferingController::class, 'createForProduct'])
            ->name('products.offerings.create');
        Route::post('products/{organizationProduct}/offerings', [VendorProductOfferingController::class, 'storeForProduct'])
            ->name('products.offerings.store');
        Route::get('products/{organizationProduct}/offerings/{vendorProductOffering}', [VendorProductOfferingController::class, 'showForProduct'])
            ->name('products.offerings.show');
        Route::get('products/{organizationProduct}/offerings/{vendorProductOffering}/edit', [VendorProductOfferingController::class, 'editForProduct'])
            ->name('products.offerings.edit');
        Route::patch('products/{organizationProduct}/offerings/{vendorProductOffering}', [VendorProductOfferingController::class, 'updateForProduct'])
            ->name('products.offerings.update');
        Route::post('products/{organizationProduct}/offerings/{vendorProductOffering}/discontinue', [VendorProductOfferingController::class, 'discontinueForProduct'])
            ->name('products.offerings.discontinue');
        Route::post('products/{organizationProduct}/offerings/{vendorProductOffering}/reactivate', [VendorProductOfferingController::class, 'reactivateForProduct'])
            ->name('products.offerings.reactivate');

        Route::get('products/{organizationProduct}/edit-master', [OrganizationProductController::class, 'editMaster'])
            ->name('products.edit-master');
        Route::patch('products/{organizationProduct}/master', [OrganizationProductController::class, 'updateMaster'])
            ->name('products.update-master');
        Route::get('products/{organizationProduct}/edit-settings', [OrganizationProductController::class, 'editSettings'])
            ->name('products.edit-settings');
        Route::patch('products/{organizationProduct}/settings', [OrganizationProductController::class, 'updateSettings'])
            ->name('products.update-settings');
        Route::get('products/{organizationProduct}/edit-pricing', [OrganizationProductController::class, 'editPricing'])
            ->name('products.edit-pricing');
        Route::patch('products/{organizationProduct}/pricing', [OrganizationProductController::class, 'updatePricing'])
            ->name('products.update-pricing');
        Route::patch('products/{organizationProduct}/purchase-cost', [OrganizationProductController::class, 'updatePurchaseCost'])
            ->name('products.update-purchase-cost');
        Route::post('products/{organizationProduct}/archive', [OrganizationProductController::class, 'archive'])
            ->name('products.archive');

        Route::post('products/{organizationProduct}/components', [OrganizationProductController::class, 'storeComponent'])
            ->name('products.components.store');
        Route::patch('products/{organizationProduct}/components/{component}', [OrganizationProductController::class, 'updateComponent'])
            ->name('products.components.update');
        Route::post('products/{organizationProduct}/components/{component}/deactivate', [OrganizationProductController::class, 'deactivateComponent'])
            ->name('products.components.deactivate');
        Route::post('products/{organizationProduct}/components/{component}/reactivate', [OrganizationProductController::class, 'reactivateComponent'])
            ->name('products.components.reactivate');

        Route::post('products/{organizationProduct}/conversions/preview', [OrganizationProductController::class, 'previewConversion'])
            ->name('products.conversions.preview');
        Route::post('products/{organizationProduct}/conversions', [OrganizationProductController::class, 'storeConversion'])
            ->name('products.conversions.store');
        Route::patch('products/{organizationProduct}/conversions/{unitConversion}', [OrganizationProductController::class, 'updateConversion'])
            ->name('products.conversions.update');
        Route::post('products/{organizationProduct}/conversions/{unitConversion}/deactivate', [OrganizationProductController::class, 'deactivateConversion'])
            ->name('products.conversions.deactivate');
        Route::post('products/{organizationProduct}/conversions/{unitConversion}/reactivate', [OrganizationProductController::class, 'reactivateConversion'])
            ->name('products.conversions.reactivate');
    });

require __DIR__.'/settings.php';
