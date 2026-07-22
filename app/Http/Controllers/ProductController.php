<?php

namespace App\Http\Controllers;

use App\Enums\UnitOfMeasure;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Product::class);

        /** @var User $user */
        $user = $request->user();

        $products = Product::query()
            ->with(['vendor:id,name', 'category:id,name'])
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

        return Inertia::render('products/Index', [
            'products' => $products,
            'filters' => [
                'search' => $request->string('search')->toString(),
                'category_id' => $request->integer('category_id') ?: null,
                'vendor_id' => $request->integer('vendor_id') ?: null,
            ],
            'categories' => ProductCategory::query()->orderBy('name')->get(['id', 'name']),
            'vendors' => Vendor::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canManage' => $user->can('create', Product::class),
            'canViewCost' => $user->isAdmin(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Product::class);

        return Inertia::render('products/Create', [
            'vendors' => Vendor::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'categories' => ProductCategory::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'units' => collect(UnitOfMeasure::cases())->map(fn (UnitOfMeasure $unit): array => [
                'value' => $unit->value,
                'label' => $unit->label(),
            ]),
            'relatedOptions' => Product::query()->orderBy('name')->get(['id', 'name', 'sku']),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $relatedIds = $data['related_product_ids'] ?? [];
        unset($data['related_product_ids']);

        $data['is_active'] = $request->boolean('is_active', true);

        $product = DB::transaction(function () use ($data, $relatedIds): Product {
            $product = Product::query()->create($data);
            $product->relatedProducts()->sync($relatedIds);

            return $product;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product created.')]);

        return to_route('products.show', $product);
    }

    public function show(Product $product): Response
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

    public function edit(Product $product): Response
    {
        $this->authorize('update', $product);

        $product->load('relatedProducts:id');

        return Inertia::render('products/Edit', [
            'product' => [
                ...ProductResource::make($product, request()->user()),
                'related_product_ids' => $product->relatedProducts->pluck('id'),
            ],
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name']),
            'categories' => ProductCategory::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'units' => collect(UnitOfMeasure::cases())->map(fn (UnitOfMeasure $unit): array => [
                'value' => $unit->value,
                'label' => $unit->label(),
            ]),
            'relatedOptions' => Product::query()
                ->whereKeyNot($product->id)
                ->orderBy('name')
                ->get(['id', 'name', 'sku']),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();
        $relatedIds = $data['related_product_ids'] ?? [];
        unset($data['related_product_ids']);

        $data['is_active'] = $request->boolean('is_active', $product->is_active);

        DB::transaction(function () use ($product, $data, $relatedIds): void {
            $product->update($data);
            $product->relatedProducts()->sync($relatedIds);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product updated.')]);

        return to_route('products.show', $product);
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);

        $product->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product deleted.')]);

        return to_route('products.index');
    }
}
