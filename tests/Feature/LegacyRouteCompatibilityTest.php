<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

test('legacy company routes preserve owner based behavior without tenant context', function () {
    $owner = User::factory()->salesman()->create();
    $other = User::factory()->salesman()->create();
    $company = Company::factory()->create(['owner_id' => $owner->id]);

    $this->actingAs($owner)
        ->get(route('companies.show', $company))
        ->assertOk();

    $this->actingAs($other)
        ->get(route('companies.show', $company))
        ->assertForbidden();

    expect(TenantContext::has())->toBeFalse();
});

test('legacy product cost visibility still uses legacy admin role', function () {
    $admin = User::factory()->admin()->create();
    $salesman = User::factory()->salesman()->create();
    $product = Product::factory()->create();

    $this->actingAs($admin)
        ->get(route('products.show', $product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('product.true_cost'));

    $this->actingAs($salesman)
        ->get(route('products.show', $product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('product.true_cost'));
});

test('legacy dashboard remains available without organization prefix', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('tenant.organization', null));
});
