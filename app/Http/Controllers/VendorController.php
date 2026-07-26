<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Controllers\Concerns\ScopesQueriesToTenant;
use App\Http\Requests\StoreVendorRequest;
use App\Http\Requests\UpdateVendorRequest;
use App\Http\Resources\VendorResource;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorProductOffering;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VendorController extends Controller
{
    use RequiresTenantContext;
    use ScopesQueriesToTenant;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Vendor::class);

        /** @var User $user */
        $user = $request->user();

        $vendorsQuery = Vendor::query()->withCount('vendorProductOfferings');

        if (TenantContext::has()) {
            $vendorsQuery = $this->scopeVendorsForRequest($vendorsQuery);
        }

        $vendors = $vendorsQuery
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('account_number', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Vendor $vendor): array => VendorResource::make($vendor, $user));

        return Inertia::render('vendors/Index', [
            'vendors' => $vendors,
            'filters' => [
                'search' => $request->string('search')->toString(),
            ],
            'canManage' => $user->can('create', Vendor::class),
            'canViewDetails' => $user->isAdmin(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Vendor::class);

        return Inertia::render('vendors/Create');
    }

    public function store(StoreVendorRequest $request): RedirectResponse
    {
        $tenant = $this->requireTenantContext();

        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active', true);
        $data['parent_account_id'] = $tenant->parentAccountId;

        $vendor = Vendor::query()->create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vendor created.')]);

        return redirect()->to(TenantRoute::to('vendors.show', $vendor));
    }

    public function show(?Organization $organization, Vendor $vendor): Response
    {
        $this->authorize('view', $vendor);

        /** @var User $user */
        $user = request()->user();

        $tenant = $this->requireTenantContext();

        return Inertia::render('vendors/Show', [
            'vendor' => VendorResource::make($vendor, $user),
            'vendorOfferings' => VendorProductOfferingController::filteredOfferingsForVendor(
                request(),
                $tenant->parentAccountId,
                $vendor->id,
            ),
            'offeringFilters' => [
                'offering_search' => request()->string('offering_search')->toString(),
                'offering_status' => request()->string('offering_status')->toString(),
            ],
            'canManage' => $user->can('update', $vendor),
            'canManageOfferings' => $user->can('create', VendorProductOffering::class),
            'canViewDetails' => $user->can('viewDetails', $vendor),
        ]);
    }

    public function edit(?Organization $organization, Vendor $vendor): Response
    {
        $this->authorize('update', $vendor);

        return Inertia::render('vendors/Edit', [
            'vendor' => $vendor,
        ]);
    }

    public function update(UpdateVendorRequest $request, ?Organization $organization, Vendor $vendor): RedirectResponse
    {
        $this->requireTenantContext();

        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active', $vendor->is_active);

        $vendor->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vendor updated.')]);

        return redirect()->to(TenantRoute::to('vendors.show', $vendor));
    }

    public function destroy(?Organization $organization, Vendor $vendor): RedirectResponse
    {
        $this->requireTenantContext();
        $this->authorize('delete', $vendor);

        $vendor->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vendor deleted.')]);

        return redirect()->to(TenantRoute::to('vendors.index'));
    }
}
