<?php

use App\Enums\PermissionEffect;
use App\Enums\UserRole;
use App\Models\Product;

test('owner sees product cost under tenant context', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $product = Product::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->get(route('org.products.show', [$ctx['organization'], $product]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('product.true_cost')
            ->where('canViewCost', true));

    $this->actingAs($ctx['user'])
        ->get(route('org.products.index', $ctx['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canViewCost', true));
});

test('salesperson without cost permission does not receive cost fields', function () {
    $ctx = createTenantUser('salesperson');
    $product = Product::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->get(route('org.products.show', [$ctx['organization'], $product]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('product.true_cost')
            ->missing('product.markup_percent')
            ->where('canViewCost', false));

    $this->actingAs($ctx['user'])
        ->get(route('org.products.index', $ctx['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canViewCost', false)
            ->missing('products.data.0.true_cost'));
});

test('legacy isAdmin does not grant tenant cost access by itself', function () {
    $ctx = createTenantUser('salesperson');
    $ctx['user']->forceFill(['role' => UserRole::Admin])->save();

    $product = Product::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
    ]);

    $this->actingAs($ctx['user']->fresh())
        ->get(route('org.products.show', [$ctx['organization'], $product]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('product.true_cost')
            ->where('canViewCost', false));

    $this->actingAs($ctx['user']->fresh())
        ->get(route('org.products.index', $ctx['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canViewCost', false));
});

test('org user with explicit cost permission sees cost', function () {
    $ctx = createTenantUser('salesperson');
    attachOrgOverride($ctx['membership'], 'catalog.product.view_cost', PermissionEffect::Allow);

    // Re-establish membership permissions via a fresh request after override.
    $product = Product::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->get(route('org.products.show', [$ctx['organization'], $product]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('product.true_cost')
            ->where('canViewCost', true));
});

test('parent cost permission grants cost under tenant context', function () {
    $ctx = createTenantUser('salesperson', 'parent_owner');
    $product = Product::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->get(route('org.products.show', [$ctx['organization'], $product]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('product.true_cost')
            ->where('canViewCost', true));

    $this->actingAs($ctx['user'])
        ->get(route('org.products.index', $ctx['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canViewCost', true));
});
