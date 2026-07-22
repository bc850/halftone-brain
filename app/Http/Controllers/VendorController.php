<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVendorRequest;
use App\Http\Requests\UpdateVendorRequest;
use App\Http\Resources\VendorResource;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VendorController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Vendor::class);

        /** @var User $user */
        $user = $request->user();

        $vendors = Vendor::query()
            ->withCount('products')
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
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active', true);

        $vendor = Vendor::query()->create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vendor created.')]);

        return to_route('vendors.show', $vendor);
    }

    public function show(Vendor $vendor): Response
    {
        $this->authorize('view', $vendor);

        /** @var User $user */
        $user = request()->user();

        $vendor->load(['products' => fn ($query) => $query->with(['vendor:id,name', 'category:id,name'])->orderBy('name')->limit(50)]);

        return Inertia::render('vendors/Show', [
            'vendor' => VendorResource::make($vendor, $user),
            'canManage' => $user->can('update', $vendor),
            'canViewDetails' => $user->can('viewDetails', $vendor),
        ]);
    }

    public function edit(Vendor $vendor): Response
    {
        $this->authorize('update', $vendor);

        return Inertia::render('vendors/Edit', [
            'vendor' => $vendor,
        ]);
    }

    public function update(UpdateVendorRequest $request, Vendor $vendor): RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active', $vendor->is_active);

        $vendor->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vendor updated.')]);

        return to_route('vendors.show', $vendor);
    }

    public function destroy(Vendor $vendor): RedirectResponse
    {
        $this->authorize('delete', $vendor);

        $vendor->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vendor deleted.')]);

        return to_route('vendors.index');
    }
}
