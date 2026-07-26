<?php

namespace App\Http\Controllers;

use App\Enums\VendorProductOfferingStatus;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Requests\ActivateOrganizationProductSourceRequest;
use App\Http\Requests\ClearPreferredOrganizationProductSourceRequest;
use App\Http\Requests\DeactivateOrganizationProductSourceRequest;
use App\Http\Requests\SelectPreferredOrganizationProductSourceRequest;
use App\Http\Requests\StoreOrganizationProductSourceRequest;
use App\Http\Requests\UpdateOrganizationProductSourcePriceRequest;
use App\Http\Resources\OrganizationProductSourceResource;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\OrganizationProductSource;
use App\Models\User;
use App\Models\VendorProductOffering;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Catalog\OrganizationProductSourceService;
use App\Support\Tenancy\TenantRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationProductSourceController extends Controller
{
    use RequiresTenantContext;

    public function __construct(private OrganizationProductSourceService $sources) {}

    public function create(?Organization $organization, OrganizationProduct $organizationProduct): Response
    {
        $this->authorize('attach', [OrganizationProductSource::class, $organizationProduct]);
        $this->authorize('view', $organizationProduct);

        $tenant = $this->requireTenantContext();
        $organizationProduct->load('product');

        $attachedOfferingIds = OrganizationProductSource::query()
            ->where('organization_product_id', $organizationProduct->id)
            ->pluck('vendor_product_offering_id')
            ->all();

        $offerings = VendorProductOffering::query()
            ->with('vendor:id,name')
            ->where('parent_account_id', $tenant->parentAccountId)
            ->where('product_id', $organizationProduct->product_id)
            ->where('status', VendorProductOfferingStatus::Active)
            ->whereNotIn('id', $attachedOfferingIds)
            ->orderBy('vendor_sku')
            ->get();

        return Inertia::render('sources/Create', [
            'organizationProduct' => [
                'id' => $organizationProduct->id,
                'name' => $organizationProduct->product->name,
                'sku' => $organizationProduct->product->sku,
                'currency_code' => $organizationProduct->currency_code,
                'purchase_unit_of_measure' => $organizationProduct->purchase_unit_of_measure?->value,
                'is_purchasable' => $organizationProduct->is_purchasable,
            ],
            'offerings' => $offerings->map(fn (VendorProductOffering $offering): array => [
                'id' => $offering->id,
                'vendor_sku' => $offering->vendor_sku,
                'vendor_description' => $offering->vendor_description,
                'purchase_uom' => $offering->purchase_uom->value,
                'purchase_uom_label' => $offering->purchase_uom->label(),
                'package_quantity' => ComponentCostEstimator::scaledToQuantity(
                    $offering->package_quantity_scaled,
                ),
                'vendor' => $offering->vendor === null ? null : [
                    'id' => $offering->vendor->id,
                    'name' => $offering->vendor->name,
                ],
            ])->values()->all(),
            'returnUrl' => TenantRoute::to('products.show', $organizationProduct),
        ]);
    }

    public function store(
        StoreOrganizationProductSourceRequest $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();
        $this->authorize('view', $organizationProduct);

        $source = $this->sources->attach(
            $tenant,
            $request->user(),
            $request,
            $organizationProduct,
            $request->validated(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vendor source attached.')]);

        return redirect()->to(TenantRoute::to('products.sources.show', [
            'organizationProduct' => $organizationProduct,
            'organizationProductSource' => $source,
        ]));
    }

    public function show(
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
        OrganizationProductSource $organizationProductSource,
    ): Response {
        $this->authorize('view', $organizationProduct);
        $this->authorize('view', $organizationProductSource);

        /** @var User $user */
        $user = request()->user();
        $organizationProductSource->load([
            'vendorProductOffering.vendor',
            'vendorProductOffering.product',
            'priceEvents',
        ]);

        return Inertia::render('sources/Show', [
            'organizationProduct' => [
                'id' => $organizationProduct->id,
                'preferred_source_id' => $organizationProduct->preferred_source_id,
                'purchase_unit_of_measure' => $organizationProduct->purchase_unit_of_measure?->value,
                'is_purchasable' => $organizationProduct->is_purchasable,
            ],
            'source' => OrganizationProductSourceResource::make(
                $organizationProductSource,
                $user,
                $organizationProduct,
            ),
            'priceEvents' => OrganizationProductSourceResource::priceEvents($organizationProductSource, $user),
            'canUpdatePrice' => $user->can('updatePrice', $organizationProductSource),
            'canActivate' => $user->can('activate', $organizationProductSource),
            'canDeactivate' => $user->can('deactivate', $organizationProductSource),
            'canSelectPreferred' => $user->can('selectPreferred', $organizationProductSource),
            'canClearPreferred' => $user->can('clearPreferred', [OrganizationProductSource::class, $organizationProduct]),
            'canViewCost' => $user->can('viewCost', $organizationProductSource),
            'returnUrl' => TenantRoute::to('products.show', $organizationProduct),
        ]);
    }

    public function updatePrice(
        UpdateOrganizationProductSourcePriceRequest $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
        OrganizationProductSource $organizationProductSource,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();
        $this->authorize('view', $organizationProduct);

        $this->sources->updatePackagePrice(
            $tenant,
            $request->user(),
            $request,
            $organizationProductSource,
            $request->validated(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Source package price updated.')]);

        return redirect()->to(TenantRoute::to('products.sources.show', [
            'organizationProduct' => $organizationProduct,
            'organizationProductSource' => $organizationProductSource,
        ]));
    }

    public function activate(
        ActivateOrganizationProductSourceRequest $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
        OrganizationProductSource $organizationProductSource,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();
        $this->authorize('view', $organizationProduct);

        $this->sources->activate($tenant, $request->user(), $request, $organizationProductSource);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vendor source activated.')]);

        return redirect()->to(TenantRoute::to('products.sources.show', [
            'organizationProduct' => $organizationProduct,
            'organizationProductSource' => $organizationProductSource,
        ]));
    }

    public function deactivate(
        DeactivateOrganizationProductSourceRequest $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
        OrganizationProductSource $organizationProductSource,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();
        $this->authorize('view', $organizationProduct);

        $this->sources->deactivate($tenant, $request->user(), $request, $organizationProductSource);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vendor source deactivated.')]);

        return redirect()->to(TenantRoute::to('products.sources.show', [
            'organizationProduct' => $organizationProduct,
            'organizationProductSource' => $organizationProductSource,
        ]));
    }

    public function selectPreferred(
        SelectPreferredOrganizationProductSourceRequest $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
        OrganizationProductSource $organizationProductSource,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();
        $this->authorize('view', $organizationProduct);

        $this->sources->selectPreferred(
            $tenant,
            $request->user(),
            $request,
            $organizationProduct,
            $organizationProductSource,
            $request->validated(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Preferred vendor source selected. Effective material cost updated for this organization.'),
        ]);

        return redirect()->to(TenantRoute::to('products.show', $organizationProduct));
    }

    public function clearPreferred(
        ClearPreferredOrganizationProductSourceRequest $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();
        $this->authorize('view', $organizationProduct);

        $this->sources->clearPreferred(
            $tenant,
            $request->user(),
            $request,
            $organizationProduct,
            $request->validated(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Preferred vendor source cleared. Manual purchase-cost editing is available again.'),
        ]);

        return redirect()->to(TenantRoute::to('products.show', $organizationProduct));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function sourcesForProduct(OrganizationProduct $organizationProduct, Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        $sources = OrganizationProductSource::query()
            ->with(['vendorProductOffering.vendor'])
            ->where('organization_product_id', $organizationProduct->id)
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get();

        return OrganizationProductSourceResource::collection($sources, $user, $organizationProduct);
    }
}
