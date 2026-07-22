<?php

use App\Enums\UnitOfMeasure;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Money;

test('admins can create products with suggested sell from cost and markup', function () {
    $admin = User::factory()->admin()->create();
    $vendor = Vendor::factory()->create();
    $category = ProductCategory::factory()->create();

    $this->actingAs($admin)
        ->post(route('products.store'), [
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
    $admin = User::factory()->admin()->create();
    $related = Product::factory()->create();

    $this->actingAs($admin)
        ->post(route('products.store'), [
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
    $salesman = User::factory()->salesman()->create();
    $product = Product::factory()->create();

    $this->actingAs($salesman)
        ->get(route('products.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('products/Index')
            ->where('canViewCost', false));

    $this->actingAs($salesman)
        ->get(route('products.show', $product))
        ->assertOk();

    $this->actingAs($salesman)
        ->post(route('products.store'), [
            'name' => 'Nope',
            'sku' => 'NOPE-1',
            'unit_of_measure' => UnitOfMeasure::Each->value,
            'true_cost' => 10,
            'markup_percent' => 10,
        ])
        ->assertForbidden();
});

test('salesmen cannot see product cost fields', function () {
    $salesman = User::factory()->salesman()->create();
    $product = Product::factory()->create();

    $this->actingAs($salesman)
        ->get(route('products.show', $product))
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
