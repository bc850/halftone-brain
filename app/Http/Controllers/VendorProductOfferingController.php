<?php

namespace App\Http\Controllers;

use App\Enums\UnitOfMeasure;
use App\Enums\VendorProductOfferingStatus;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Requests\DiscontinueVendorProductOfferingRequest;
use App\Http\Requests\ReactivateVendorProductOfferingRequest;
use App\Http\Requests\StoreVendorProductOfferingRequest;
use App\Http\Requests\UpdateVendorProductOfferingRequest;
use App\Http\Resources\VendorProductOfferingResource;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\VendorProductOffering;
use App\Support\Catalog\VendorProductOfferingService;
use App\Support\Tenancy\TenantRoute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VendorProductOfferingController extends Controller
{
    use RequiresTenantContext;

    public function __construct(private VendorProductOfferingService $offerings) {}

    public function createForProduct(
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
    ): Response {
        $this->authorize('create', VendorProductOffering::class);
        $this->authorize('view', $organizationProduct);

        $tenant = $this->requireTenantContext();
        $organizationProduct->load('product');

        return Inertia::render('offerings/Create', [
            'context' => 'product',
            'organizationProductId' => $organizationProduct->id,
            'product' => [
                'id' => $organizationProduct->product->id,
                'name' => $organizationProduct->product->name,
                'sku' => $organizationProduct->product->sku,
            ],
            'vendors' => Vendor::query()
                ->where('parent_account_id', $tenant->parentAccountId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Vendor $vendor): array => [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                ])
                ->all(),
            'products' => [],
            'units' => $this->unitOptions(),
            'returnUrl' => TenantRoute::to('products.show', $organizationProduct),
        ]);
    }

    public function createForVendor(?Organization $organization, Vendor $vendor): Response
    {
        $this->authorize('create', VendorProductOffering::class);
        $this->authorize('view', $vendor);

        $tenant = $this->requireTenantContext();

        return Inertia::render('offerings/Create', [
            'context' => 'vendor',
            'vendor' => [
                'id' => $vendor->id,
                'name' => $vendor->name,
            ],
            'vendors' => [],
            'products' => Product::query()
                ->where('parent_account_id', $tenant->parentAccountId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'sku'])
                ->map(fn (Product $product): array => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                ])
                ->all(),
            'units' => $this->unitOptions(),
            'returnUrl' => TenantRoute::to('vendors.show', $vendor),
        ]);
    }

    public function storeForProduct(
        StoreVendorProductOfferingRequest $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();
        $this->authorize('view', $organizationProduct);

        $data = $request->validated();
        $data['product_id'] = $organizationProduct->product_id;

        $offering = $this->offerings->create($tenant, $request->user(), $request, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vendor offering created.')]);

        return redirect()->to(TenantRoute::to('products.offerings.show', [
            'organizationProduct' => $organizationProduct,
            'vendorProductOffering' => $offering,
        ]));
    }

    public function storeForVendor(
        StoreVendorProductOfferingRequest $request,
        ?Organization $organization,
        Vendor $vendor,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();
        $this->authorize('view', $vendor);

        $data = $request->validated();
        $data['vendor_id'] = $vendor->id;

        $offering = $this->offerings->create($tenant, $request->user(), $request, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vendor offering created.')]);

        return redirect()->to(TenantRoute::to('vendors.offerings.show', [
            'vendor' => $vendor,
            'vendorProductOffering' => $offering,
        ]));
    }

    public function showForProduct(
        Request $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
        VendorProductOffering $vendorProductOffering,
    ): Response {
        $this->authorize('view', $organizationProduct);
        $this->authorize('view', $vendorProductOffering);

        return Inertia::render('offerings/Show', [
            'offering' => VendorProductOfferingResource::make($vendorProductOffering),
            'canManage' => $request->user()->can('update', $vendorProductOffering),
            'context' => 'product',
            'organizationProductId' => $organizationProduct->id,
            'returnUrl' => TenantRoute::to('products.show', $organizationProduct),
        ]);
    }

    public function showForVendor(
        Request $request,
        ?Organization $organization,
        Vendor $vendor,
        VendorProductOffering $vendorProductOffering,
    ): Response {
        $this->authorize('view', $vendor);
        $this->authorize('view', $vendorProductOffering);

        return Inertia::render('offerings/Show', [
            'offering' => VendorProductOfferingResource::make($vendorProductOffering),
            'canManage' => $request->user()->can('update', $vendorProductOffering),
            'context' => 'vendor',
            'vendorId' => $vendor->id,
            'returnUrl' => TenantRoute::to('vendors.show', $vendor),
        ]);
    }

    public function editForProduct(
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
        VendorProductOffering $vendorProductOffering,
    ): Response {
        $this->authorize('view', $organizationProduct);
        $this->authorize('update', $vendorProductOffering);

        return Inertia::render('offerings/Edit', [
            'offering' => VendorProductOfferingResource::make($vendorProductOffering),
            'units' => $this->unitOptions(),
            'context' => 'product',
            'organizationProductId' => $organizationProduct->id,
            'returnUrl' => TenantRoute::to('products.offerings.show', [
                'organizationProduct' => $organizationProduct,
                'vendorProductOffering' => $vendorProductOffering,
            ]),
        ]);
    }

    public function editForVendor(
        ?Organization $organization,
        Vendor $vendor,
        VendorProductOffering $vendorProductOffering,
    ): Response {
        $this->authorize('view', $vendor);
        $this->authorize('update', $vendorProductOffering);

        return Inertia::render('offerings/Edit', [
            'offering' => VendorProductOfferingResource::make($vendorProductOffering),
            'units' => $this->unitOptions(),
            'context' => 'vendor',
            'vendorId' => $vendor->id,
            'returnUrl' => TenantRoute::to('vendors.offerings.show', [
                'vendor' => $vendor,
                'vendorProductOffering' => $vendorProductOffering,
            ]),
        ]);
    }

    public function updateForProduct(
        UpdateVendorProductOfferingRequest $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
        VendorProductOffering $vendorProductOffering,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();
        $this->authorize('view', $organizationProduct);

        $this->offerings->update(
            $tenant,
            $request->user(),
            $request,
            $vendorProductOffering,
            $request->validated(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vendor offering updated.')]);

        return redirect()->to(TenantRoute::to('products.offerings.show', [
            'organizationProduct' => $organizationProduct,
            'vendorProductOffering' => $vendorProductOffering,
        ]));
    }

    public function updateForVendor(
        UpdateVendorProductOfferingRequest $request,
        ?Organization $organization,
        Vendor $vendor,
        VendorProductOffering $vendorProductOffering,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();
        $this->authorize('view', $vendor);

        $this->offerings->update(
            $tenant,
            $request->user(),
            $request,
            $vendorProductOffering,
            $request->validated(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vendor offering updated.')]);

        return redirect()->to(TenantRoute::to('vendors.offerings.show', [
            'vendor' => $vendor,
            'vendorProductOffering' => $vendorProductOffering,
        ]));
    }

    public function discontinueForProduct(
        DiscontinueVendorProductOfferingRequest $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
        VendorProductOffering $vendorProductOffering,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();
        $this->authorize('view', $organizationProduct);

        $this->offerings->discontinue($tenant, $request->user(), $request, $vendorProductOffering);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vendor offering discontinued.')]);

        return redirect()->to(TenantRoute::to('products.offerings.show', [
            'organizationProduct' => $organizationProduct,
            'vendorProductOffering' => $vendorProductOffering,
        ]));
    }

    public function discontinueForVendor(
        DiscontinueVendorProductOfferingRequest $request,
        ?Organization $organization,
        Vendor $vendor,
        VendorProductOffering $vendorProductOffering,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();
        $this->authorize('view', $vendor);

        $this->offerings->discontinue($tenant, $request->user(), $request, $vendorProductOffering);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vendor offering discontinued.')]);

        return redirect()->to(TenantRoute::to('vendors.offerings.show', [
            'vendor' => $vendor,
            'vendorProductOffering' => $vendorProductOffering,
        ]));
    }

    public function reactivateForProduct(
        ReactivateVendorProductOfferingRequest $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
        VendorProductOffering $vendorProductOffering,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();
        $this->authorize('view', $organizationProduct);

        $this->offerings->reactivate($tenant, $request->user(), $request, $vendorProductOffering);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vendor offering reactivated.')]);

        return redirect()->to(TenantRoute::to('products.offerings.show', [
            'organizationProduct' => $organizationProduct,
            'vendorProductOffering' => $vendorProductOffering,
        ]));
    }

    public function reactivateForVendor(
        ReactivateVendorProductOfferingRequest $request,
        ?Organization $organization,
        Vendor $vendor,
        VendorProductOffering $vendorProductOffering,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();
        $this->authorize('view', $vendor);

        $this->offerings->reactivate($tenant, $request->user(), $request, $vendorProductOffering);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vendor offering reactivated.')]);

        return redirect()->to(TenantRoute::to('vendors.offerings.show', [
            'vendor' => $vendor,
            'vendorProductOffering' => $vendorProductOffering,
        ]));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function unitOptions(): array
    {
        return array_values(collect(UnitOfMeasure::cases())
            ->map(fn (UnitOfMeasure $unit): array => [
                'value' => $unit->value,
                'label' => $unit->label(),
            ])
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function filteredOfferingsForProduct(
        Request $request,
        int $parentAccountId,
        int $productId,
    ): array {
        $query = VendorProductOffering::query()
            ->with(['vendor:id,name', 'product:id,name,sku'])
            ->where('parent_account_id', $parentAccountId)
            ->where('product_id', $productId);

        self::applyOfferingFilters($query, $request);

        return VendorProductOfferingResource::collection(
            $query->orderBy('vendor_sku')->get(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function filteredOfferingsForVendor(
        Request $request,
        int $parentAccountId,
        int $vendorId,
    ): array {
        $query = VendorProductOffering::query()
            ->with(['vendor:id,name', 'product:id,name,sku'])
            ->where('parent_account_id', $parentAccountId)
            ->where('vendor_id', $vendorId);

        self::applyOfferingFilters($query, $request);

        return VendorProductOfferingResource::collection(
            $query->orderBy('vendor_sku')->get(),
        );
    }

    /**
     * @param  Builder<VendorProductOffering>  $query
     */
    private static function applyOfferingFilters($query, Request $request): void
    {
        $search = trim($request->string('offering_search')->toString());
        if ($search !== '') {
            $query->where(function ($inner) use ($search): void {
                $inner->where('vendor_sku', 'like', "%{$search}%")
                    ->orWhere('manufacturer', 'like', "%{$search}%")
                    ->orWhere('manufacturer_part_number', 'like', "%{$search}%")
                    ->orWhereHas('product', function ($productQuery) use ($search): void {
                        $productQuery->where('sku', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('vendor', function ($vendorQuery) use ($search): void {
                        $vendorQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $status = $request->string('offering_status')->toString();
        if ($status === VendorProductOfferingStatus::Active->value
            || $status === VendorProductOfferingStatus::Discontinued->value) {
            $query->where('status', $status);
        }
    }
}
