<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductCategoryRequest;
use App\Http\Requests\UpdateProductCategoryRequest;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductCategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ProductCategory::class);

        $categories = ProductCategory::query()
            ->withCount('products')
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where('name', 'like', "%{$search}%");
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('categories/Index', [
            'categories' => $categories,
            'filters' => [
                'search' => $request->string('search')->toString(),
            ],
            'canManage' => $request->user()->can('create', ProductCategory::class),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', ProductCategory::class);

        return Inertia::render('categories/Create');
    }

    public function store(StoreProductCategoryRequest $request): RedirectResponse
    {
        $category = ProductCategory::query()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category created.')]);

        return to_route('categories.show', $category);
    }

    public function show(ProductCategory $category): Response
    {
        $this->authorize('view', $category);

        $category->load(['products' => fn ($query) => $query->orderBy('name')->limit(50)]);

        return Inertia::render('categories/Show', [
            'category' => $category,
            'canManage' => request()->user()->can('update', $category),
        ]);
    }

    public function edit(ProductCategory $category): Response
    {
        $this->authorize('update', $category);

        return Inertia::render('categories/Edit', [
            'category' => $category,
        ]);
    }

    public function update(UpdateProductCategoryRequest $request, ProductCategory $category): RedirectResponse
    {
        $category->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category updated.')]);

        return to_route('categories.show', $category);
    }

    public function destroy(ProductCategory $category): RedirectResponse
    {
        $this->authorize('delete', $category);

        $category->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category deleted.')]);

        return to_route('categories.index');
    }
}
