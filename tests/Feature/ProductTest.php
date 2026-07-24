<?php

use App\Enums\UnitOfMeasure;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Vendor;
use App\Support\Money;

test('admins can create products with suggested sell from cost and markup', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $vendor = Vendor::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
    ]);
    $category = ProductCategory::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->post(route('org.products.store', $ctx['organization']), [
            'name' => '48x96 ACM Sign 3MM',
            'sku' => 'ACM-4896-3',
            'vendor_sku' => 'VEN-ACM-1',
            'vendor_id' => $vendor->id,
            'product_category_id' => $category->id,
            'unit_of_measure' => UnitOfMeasure::Each->value,
            'true_cost' => 100,
            'markup_percent' => 50,
            'is_active' => true,
        ])
        ->assertRedirect();

    $product = Product::query()->where('sku', 'ACM-4896-3')->first();

    expect($product)->not->toBeNull()
        ->and($product->list_price_cents)->toBe(15000)
        ->and(Money::centsToDollars($product->list_price_cents))->toBe('150.00')
        ->and($product->true_cost_micro_units)->toBe(1_000_000)
        ->and($product->markup_basis_points)->toBe(5000);
});

test('related products can be linked', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $related = Product::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->post(route('org.products.store', $ctx['organization']), [
            'name' => 'Vinyl Graphics Kit',
            'sku' => 'VINYL-KIT-1',
            'unit_of_measure' => UnitOfMeasure::Set->value,
            'true_cost' => 40,
            'markup_percent' => 25,
            'related_product_ids' => [$related->id],
        ])
        ->assertRedirect();

    $product = Product::query()->where('sku', 'VINYL-KIT-1')->first();

    expect($product->relatedProducts)->toHaveCount(1)
        ->and($product->relatedProducts->first()->id)->toBe($related->id);
});

test('salesmen can view products but cannot create them', function () {
    $ctx = createTenantUser('salesperson');
    $product = Product::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->get(route('org.products.index', $ctx['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('products/Index')
            ->where('canViewCost', false));

    $this->actingAs($ctx['user'])
        ->get(route('org.products.show', [$ctx['organization'], $product]))
        ->assertOk();

    $this->actingAs($ctx['user'])
        ->post(route('org.products.store', $ctx['organization']), [
            'name' => 'Nope',
            'sku' => 'NOPE-1',
            'unit_of_measure' => UnitOfMeasure::Each->value,
            'true_cost' => 10,
            'markup_percent' => 10,
        ])
        ->assertForbidden();
});

test('salesmen cannot see product cost fields', function () {
    $ctx = createTenantUser('salesperson');
    $product = Product::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->get(route('org.products.show', [$ctx['organization'], $product]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('products/Show')
            ->where('canViewCost', false)
            ->missing('product.true_cost')
            ->missing('product.markup_percent')
            ->missing('product.vendor_sku')
            ->missing('product.notes')
            ->has('product.suggested_sell_price'));
});
