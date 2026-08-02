<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\IntegrationOutboxController;
use App\Http\Controllers\MondayIntegrationSettingsController;
use App\Http\Controllers\OrganizationCompanyTaxCertificateController;
use App\Http\Controllers\OrganizationProductController;
use App\Http\Controllers\OrganizationProductSourceController;
use App\Http\Controllers\OrganizationTaxSettingsController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PublicQuoteController;
use App\Http\Controllers\QuoteApprovalController;
use App\Http\Controllers\QuoteApprovalQueueController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\QuoteCustomerLinkController;
use App\Http\Controllers\QuoteCustomerTokenController;
use App\Http\Controllers\QuoteDeliveryController;
use App\Http\Controllers\QuoteDocumentController;
use App\Http\Controllers\QuoteEmployeeResponseController;
use App\Http\Controllers\QuotePartySnapshotController;
use App\Http\Controllers\QuoteRepriceController;
use App\Http\Controllers\QuoteRevisionAdjustmentController;
use App\Http\Controllers\QuoteRevisionController;
use App\Http\Controllers\QuoteRevisionLineController;
use App\Http\Controllers\QuoteTaxController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\VendorProductOfferingController;
use App\Http\Controllers\VisibilityPreferenceController;
use App\Http\Middleware\EnforceLegacyTenantBoundary;
use App\Http\Middleware\ResolveTenantContextFromRoute;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

/*
 | Public customer quote links. No auth, no TenantContext. Rate-limited; CSRF
 | still applies to POST via the web middleware stack.
 */
Route::middleware('throttle:30,1')
    ->prefix('customer/quotes')
    ->name('public.quotes.')
    ->group(function (): void {
        Route::get('{token}', [PublicQuoteController::class, 'show'])->name('show');
        Route::get('{token}/pdf', [PublicQuoteController::class, 'pdf'])->name('pdf');
        Route::post('{token}/accept', [PublicQuoteController::class, 'accept'])->name('accept');
        Route::post('{token}/reject', [PublicQuoteController::class, 'reject'])->name('reject');
    });

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

        /*
         | Exemption certificates belong to this organization's relationship with the
         | customer, so they hang off the company rather than standing alone.
         */
        Route::prefix('companies/{company}/tax-certificates')
            ->name('companies.tax-certificates.')
            ->group(function (): void {
                Route::get('/', [OrganizationCompanyTaxCertificateController::class, 'index'])->name('index');
                Route::post('/', [OrganizationCompanyTaxCertificateController::class, 'store'])->name('store');
                Route::patch('{taxCertificate}', [OrganizationCompanyTaxCertificateController::class, 'update'])
                    ->name('update');
                Route::post('{taxCertificate}/verify', [OrganizationCompanyTaxCertificateController::class, 'verify'])
                    ->name('verify');
                Route::post('{taxCertificate}/reject', [OrganizationCompanyTaxCertificateController::class, 'reject'])
                    ->name('reject');
                Route::post('{taxCertificate}/revoke', [OrganizationCompanyTaxCertificateController::class, 'revoke'])
                    ->name('revoke');
                Route::post('{taxCertificate}/mark-expired', [OrganizationCompanyTaxCertificateController::class, 'markExpired'])
                    ->name('mark-expired');
            });

        /*
         | Quotes are tenant-only: there is no legacy `/quotes` surface to fall back to.
         */
        Route::get('deals/{deal}/quotes', [QuoteController::class, 'indexForDeal'])
            ->name('deals.quotes.index');
        Route::get('deals/{deal}/quotes/create', [QuoteController::class, 'create'])
            ->name('deals.quotes.create');
        Route::post('deals/{deal}/quotes', [QuoteController::class, 'store'])
            ->name('deals.quotes.store');

        Route::get('quotes/{quote}', [QuoteController::class, 'show'])
            ->name('quotes.show');

        Route::prefix('quotes/{quote}/revisions/{quoteRevision}')
            ->name('quotes.revisions.')
            ->group(function (): void {
                Route::get('/', [QuoteRevisionController::class, 'show'])->name('show');
                Route::get('edit', [QuoteRevisionController::class, 'edit'])->name('edit');
                Route::patch('content', [QuoteRevisionController::class, 'updateContent'])->name('content');
                Route::post('clone', [QuoteRevisionController::class, 'clone'])->name('clone');

                Route::get('party/edit', [QuotePartySnapshotController::class, 'edit'])->name('party.edit');
                Route::patch('party', [QuotePartySnapshotController::class, 'update'])->name('party.update');
                Route::post('party/refresh-preview', [QuotePartySnapshotController::class, 'refreshPreview'])
                    ->name('party.refresh-preview');
                Route::post('party/refresh', [QuotePartySnapshotController::class, 'refresh'])
                    ->name('party.refresh');

                Route::post('lines/catalog', [QuoteRevisionLineController::class, 'storeCatalog'])->name('lines.catalog');
                Route::post('lines/custom', [QuoteRevisionLineController::class, 'storeCustom'])->name('lines.custom');
                Route::post('lines/section', [QuoteRevisionLineController::class, 'storeSection'])->name('lines.section');
                Route::post('lines/note', [QuoteRevisionLineController::class, 'storeNote'])->name('lines.note');
                Route::post('lines/reorder', [QuoteRevisionLineController::class, 'reorder'])->name('lines.reorder');
                Route::patch('lines/{line}', [QuoteRevisionLineController::class, 'update'])->name('lines.update');
                Route::delete('lines/{line}', [QuoteRevisionLineController::class, 'destroy'])->name('lines.destroy');

                Route::post('lines/{line}/reprice', [QuoteRepriceController::class, 'repriceLine'])->name('lines.reprice');
                Route::post('lines/{line}/reset-override', [QuoteRepriceController::class, 'resetOverride'])
                    ->name('lines.reset-override');
                Route::post('reprice-catalog', [QuoteRepriceController::class, 'repriceCatalog'])->name('reprice-catalog');

                Route::post('adjustments', [QuoteRevisionAdjustmentController::class, 'store'])->name('adjustments.store');
                Route::patch('adjustments/{adjustment}', [QuoteRevisionAdjustmentController::class, 'update'])
                    ->name('adjustments.update');
                Route::delete('adjustments/{adjustment}', [QuoteRevisionAdjustmentController::class, 'destroy'])
                    ->name('adjustments.destroy');

                Route::post('tax/calculate', [QuoteTaxController::class, 'calculate'])->name('tax.calculate');
                Route::post('tax/override', [QuoteTaxController::class, 'override'])->name('tax.override');
                Route::get('tax/history', [QuoteTaxController::class, 'history'])->name('tax.history');

                Route::post('approvals/evaluate', [QuoteApprovalController::class, 'evaluate'])
                    ->name('approvals.evaluate');
                Route::post('approvals/submit', [QuoteApprovalController::class, 'submit'])
                    ->name('approvals.submit');
                Route::post('approvals/withdraw', [QuoteApprovalController::class, 'withdraw'])
                    ->name('approvals.withdraw');
                Route::post('approvals/return-to-draft', [QuoteApprovalController::class, 'returnToDraft'])
                    ->name('approvals.return-to-draft');

                Route::post('documents/generate', [QuoteDocumentController::class, 'generate'])
                    ->name('documents.generate');
                Route::get('documents/{document}/preview', [QuoteDocumentController::class, 'preview'])
                    ->name('documents.preview');
                Route::get('documents/{document}/download', [QuoteDocumentController::class, 'download'])
                    ->name('documents.download');

                Route::get('delivery', [QuoteDeliveryController::class, 'show'])->name('delivery');
                Route::post('customer-link/prepare', [QuoteCustomerLinkController::class, 'prepare'])
                    ->name('customer-link.prepare');
                Route::post('deliveries/{delivery}/record-manual', [QuoteDeliveryController::class, 'recordManual'])
                    ->name('deliveries.record-manual');
                Route::post('tokens/{customerAccessToken}/revoke', [QuoteCustomerTokenController::class, 'revoke'])
                    ->name('tokens.revoke');
                Route::post('tokens/regenerate', [QuoteCustomerTokenController::class, 'regenerate'])
                    ->name('tokens.regenerate');

                Route::post('employee-responses/accept', [QuoteEmployeeResponseController::class, 'accept'])
                    ->name('employee-responses.accept');
                Route::post('employee-responses/reject', [QuoteEmployeeResponseController::class, 'reject'])
                    ->name('employee-responses.reject');
            });

        /*
         | The approver's queue is organization-wide rather than nested under a quote:
         | it exists to find the quotes waiting on a decision.
         */
        Route::get('quote-approvals', [QuoteApprovalQueueController::class, 'index'])
            ->name('quote-approvals.index');
        Route::post('quote-approvals/{approvalRequest}/approve', [QuoteApprovalQueueController::class, 'approve'])
            ->name('quote-approvals.approve');
        Route::post('quote-approvals/{approvalRequest}/reject', [QuoteApprovalQueueController::class, 'reject'])
            ->name('quote-approvals.reject');

        Route::prefix('integrations/outbox')
            ->name('integrations.outbox.')
            ->group(function (): void {
                Route::get('/', [IntegrationOutboxController::class, 'index'])->name('index');
                Route::get('health', [IntegrationOutboxController::class, 'health'])->name('health');
                Route::get('events/{outboxEvent}', [IntegrationOutboxController::class, 'showEvent'])->name('events.show');
                Route::get('deliveries/{outboxDelivery}', [IntegrationOutboxController::class, 'showDelivery'])->name('deliveries.show');
                Route::post('deliveries/{outboxDelivery}/replay', [IntegrationOutboxController::class, 'replay'])->name('deliveries.replay');
                Route::post('deliveries/{outboxDelivery}/abandon', [IntegrationOutboxController::class, 'abandon'])->name('deliveries.abandon');
            });

        Route::prefix('integrations/settings/monday')
            ->name('integrations.settings.monday.')
            ->group(function (): void {
                Route::get('/', [MondayIntegrationSettingsController::class, 'show'])->name('show');
                Route::post('/', [MondayIntegrationSettingsController::class, 'store'])->name('store');
                Route::put('{mondaySetting}', [MondayIntegrationSettingsController::class, 'update'])->name('update');
                Route::post('{mondaySetting}/validate', [MondayIntegrationSettingsController::class, 'validateConfiguration'])
                    ->name('validate');
                Route::post('{mondaySetting}/enable', [MondayIntegrationSettingsController::class, 'enable'])->name('enable');
                Route::post('{mondaySetting}/disable', [MondayIntegrationSettingsController::class, 'disable'])->name('disable');
            });

        Route::prefix('tax-settings')
            ->name('tax-settings.')
            ->group(function (): void {
                Route::get('/', [OrganizationTaxSettingsController::class, 'edit'])->name('edit');
                Route::put('profile', [OrganizationTaxSettingsController::class, 'updateProfile'])->name('profile');
                Route::post('rates', [OrganizationTaxSettingsController::class, 'storeRate'])->name('rates.store');
                Route::patch('rates/{taxRate}', [OrganizationTaxSettingsController::class, 'updateRate'])
                    ->name('rates.update');
                Route::post('rates/{taxRate}/deactivate', [OrganizationTaxSettingsController::class, 'deactivateRate'])
                    ->name('rates.deactivate');
                Route::post('rates/{taxRate}/supersede', [OrganizationTaxSettingsController::class, 'supersedeRate'])
                    ->name('rates.supersede');
            });

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

        Route::get('products/{organizationProduct}/sources/create', [OrganizationProductSourceController::class, 'create'])
            ->name('products.sources.create');
        Route::post('products/{organizationProduct}/sources', [OrganizationProductSourceController::class, 'store'])
            ->name('products.sources.store');
        Route::post('products/{organizationProduct}/sources/clear-preferred', [OrganizationProductSourceController::class, 'clearPreferred'])
            ->name('products.sources.clear-preferred');
        Route::get('products/{organizationProduct}/sources/{organizationProductSource}', [OrganizationProductSourceController::class, 'show'])
            ->name('products.sources.show');
        Route::patch('products/{organizationProduct}/sources/{organizationProductSource}/price', [OrganizationProductSourceController::class, 'updatePrice'])
            ->name('products.sources.update-price');
        Route::post('products/{organizationProduct}/sources/{organizationProductSource}/activate', [OrganizationProductSourceController::class, 'activate'])
            ->name('products.sources.activate');
        Route::post('products/{organizationProduct}/sources/{organizationProductSource}/deactivate', [OrganizationProductSourceController::class, 'deactivate'])
            ->name('products.sources.deactivate');
        Route::post('products/{organizationProduct}/sources/{organizationProductSource}/prefer', [OrganizationProductSourceController::class, 'selectPreferred'])
            ->name('products.sources.prefer');

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
