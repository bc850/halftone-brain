<?php

namespace App\Http\Controllers;

use App\Enums\UnitOfMeasure;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Controllers\Concerns\ScopesQueriesToTenant;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    use RequiresTenantContext;
    use ScopesQueriesToTenant;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Product::class);

        /** @var User $user */
        $user = $request->user();

        $productsQuery = Product::query()->with(['vendor:id,name', 'category:id,name']);

        if (TenantContext::has()) {
            $productsQuery = $this->scopeProductsForRequest($productsQuery);
        }

        $products = $productsQuery
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('vendor_sku', 'like', "%{$search}%");
                });
            })
            ->when($request->integer('category_id') ?: null, fn ($query, int $categoryId) => $query->where('product_category_id', $categoryId))
            ->when($request->integer('vendor_id') ?: null, fn ($query, int $vendorId) => $query->where('vendor_id', $vendorId))
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Product $product): array => ProductResource::make($product, $user));

        $categoriesQuery = ProductCategory::query()->orderBy('name');
        $vendorsQuery = Vendor::query()->where('is_active', true)->orderBy('name');

        if (TenantContext::has()) {
            $categoriesQuery = $this->scopeCategoriesForRequest($categoriesQuery);
            $vendorsQuery = $this->scopeVendorsForRequest($vendorsQuery);
        }

        return Inertia::render('products/Index', [
            'products' => $products,
            'filters' => [
                'search' => $request->string('search')->toString(),
                'category_id' => $request->integer('category_id') ?: null,
                'vendor_id' => $request->integer('vendor_id') ?: null,
            ],
            'categories' => $categoriesQuery->get(['id', 'name']),
            'vendors' => $vendorsQuery->get(['id', 'name']),
            'canManage' => $user->can('create', Product::class),
            'canViewCost' => TenantContext::has()
                ? TenantContext::get()->canViewCost()
                : $user->isAdmin(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Product::class);

        $vendorsQuery = Vendor::query()->where('is_active', true)->orderBy('name');
        $categoriesQuery = ProductCategory::query()->orderBy('sort_order')->orderBy('name');
        $relatedOptionsQuery = Product::query()->orderBy('name');

        if (TenantContext::has()) {
            $vendorsQuery = $this->scopeVendorsForRequest($vendorsQuery);
            $categoriesQuery = $this->scopeCategoriesForRequest($categoriesQuery);
            $relatedOptionsQuery = $this->scopeProductsForRequest($relatedOptionsQuery);
        }

        return Inertia::render('products/Create', [
            'vendors' => $vendorsQuery->get(['id', 'name']),
            'categories' => $categoriesQuery->get(['id', 'name']),
            'units' => collect(UnitOfMeasure::cases())->map(fn (UnitOfMeasure $unit): array => [
                'value' => $unit->value,
                'label' => $unit->label(),
            ]),
            'relatedOptions' => $relatedOptionsQuery->get(['id', 'name', 'sku']),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $tenant = $this->requireTenantContext();

        $data = $request->validated();
        $relatedIds = $data['related_product_ids'] ?? [];
        unset($data['related_product_ids']);

        $data['is_active'] = $request->boolean('is_active', true);
        $data['parent_account_id'] = $tenant->parentAccountId;

        $product = DB::transaction(function () use ($data, $relatedIds): Product {
            $product = Product::query()->create($data);
            $product->relatedProducts()->sync($relatedIds);

            return $product;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product created.')]);

        return redirect()->to(TenantRoute::to('products.show', $product));
    }

    public function show(?Organization $organization, Product $product): Response
    {
        $this->authorize('view', $product);

        /** @var User $user */
        $user = request()->user();

        $product->load([
            'vendor:id,name',
            'category:id,name',
            'relatedProducts:id,name,sku,list_price_cents',
        ]);

        return Inertia::render('products/Show', [
            'product' => ProductResource::make($product, $user),
            'canManage' => $user->can('update', $product),
            'canViewCost' => $user->can('viewCost', $product),
        ]);
    }

    public function edit(?Organization $organization, Product $product): Response
    {
        $this->authorize('update', $product);

        $product->load('relatedProducts:id');

        $vendorsQuery = Vendor::query()->orderBy('name');
        $categoriesQuery = ProductCategory::query()->orderBy('sort_order')->orderBy('name');
        $relatedOptionsQuery = Product::query()->whereKeyNot($product->id)->orderBy('name');

        if (TenantContext::has()) {
            $vendorsQuery = $this->scopeVendorsForRequest($vendorsQuery);
            $categoriesQuery = $this->scopeCategoriesForRequest($categoriesQuery);
            $relatedOptionsQuery = $this->scopeProductsForRequest($relatedOptionsQuery);
        }

        return Inertia::render('products/Edit', [
            'product' => [
                ...ProductResource::make($product, request()->user()),
                'related_product_ids' => $product->relatedProducts->pluck('id'),
            ],
            'vendors' => $vendorsQuery->get(['id', 'name']),
            'categories' => $categoriesQuery->get(['id', 'name']),
            'units' => collect(UnitOfMeasure::cases())->map(fn (UnitOfMeasure $unit): array => [
                'value' => $unit->value,
                'label' => $unit->label(),
            ]),
            'relatedOptions' => $relatedOptionsQuery->get(['id', 'name', 'sku']),
        ]);
    }

    public function update(UpdateProductRequest $request, ?Organization $organization, Product $product): RedirectResponse
    {
        $this->requireTenantContext();

        $data = $request->validated();
        $relatedIds = $data['related_product_ids'] ?? [];
        unset($data['related_product_ids']);

        $data['is_active'] = $request->boolean('is_active', $product->is_active);

        DB::transaction(function () use ($product, $data, $relatedIds): void {
            $product->update($data);
            $product->relatedProducts()->sync($relatedIds);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product updated.')]);

        return redirect()->to(TenantRoute::to('products.show', $product));
    }

    public function destroy(?Organization $organization, Product $product): RedirectResponse
    {
        $this->requireTenantContext();
        $this->authorize('delete', $product);

        $product->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product deleted.')]);

        return redirect()->to(TenantRoute::to('products.index'));
    }
}
