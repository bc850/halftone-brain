<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\OrganizationProductController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\VendorController;
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
        Route::post('products/{organizationProduct}/archive', [OrganizationProductController::class, 'archive'])
            ->name('products.archive');
    });

require __DIR__.'/settings.php';
