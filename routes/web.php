<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\VisibilityPreferenceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::patch('preferences/visibility', [VisibilityPreferenceController::class, 'update'])
        ->name('preferences.visibility');

    Route::resource('companies', CompanyController::class);
    Route::resource('contacts', ContactController::class);
    Route::resource('deals', DealController::class);
    Route::patch('deals/{deal}/stage', [DealController::class, 'updateStage'])->name('deals.stage');

    Route::resource('vendors', VendorController::class);
    Route::resource('categories', ProductCategoryController::class)
        ->parameters(['categories' => 'category']);
    Route::resource('products', ProductController::class);
});

require __DIR__.'/settings.php';
