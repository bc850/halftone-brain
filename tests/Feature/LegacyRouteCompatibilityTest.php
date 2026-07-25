<?php

use App\Models\Company;
use App\Models\OrganizationProduct;
use App\Models\Product;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

test('legacy company show redirects into organization context for members', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $company = Company::factory()->create([
        'owner_id' => $ctx['user']->id,
        'parent_account_id' => $ctx['parent']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->get(route('companies.show', $company))
        ->assertRedirect(route('org.companies.show', [
            'organization' => $ctx['organization'],
            'company' => $company,
        ]));
});

test('legacy product show redirects and tenant context is cleared afterwards', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $product = Product::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
    ]);
    $organizationProduct = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'product_id' => $product->id,
    ]);

    $this->actingAs($ctx['user'])
        ->get(route('products.show', $product))
        ->assertRedirect(route('org.products.show', [
            'organization' => $ctx['organization'],
            'organizationProduct' => $organizationProduct,
        ]));

    expect(TenantContext::has())->toBeFalse();
});

test('users without organization membership cannot use legacy dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertForbidden();
});
