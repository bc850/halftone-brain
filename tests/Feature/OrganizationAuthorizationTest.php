<?php

use App\Enums\PermissionEffect;
use App\Models\Company;
use App\Models\OrganizationCompany;
use App\Models\Product;

test('organization admin can view companies but cannot update shared identity without parent permission', function () {
    $fixture = createTenantUser('admin');

    $company = Company::factory()->create([
        'owner_id' => $fixture['user']->id,
        'parent_account_id' => $fixture['parent']->id,
    ]);
    OrganizationCompany::factory()->create([
        'organization_id' => $fixture['organization']->id,
        'company_id' => $company->id,
        'parent_account_id' => $fixture['parent']->id,
    ]);

    $this->actingAs($fixture['user'])
        ->get(route('org.companies.show', [$fixture['organization'], $company]))
        ->assertOk();

    $this->actingAs($fixture['user'])
        ->put(route('org.companies.update', [$fixture['organization'], $company]), [
            'name' => 'Changed',
            'owner_id' => $fixture['user']->id,
            'sales_tax_status' => $company->sales_tax_status->value,
        ])
        ->assertForbidden();
});

test('parent catalog manager with org admin can update products', function () {
    $fixture = createTenantUser('admin', 'parent_catalog_manager');

    $product = Product::factory()->create([
        'parent_account_id' => $fixture['parent']->id,
    ]);

    $this->actingAs($fixture['user'])
        ->put(route('org.products.update', [$fixture['organization'], $product]), [
            'name' => 'Catalog Updated',
            'sku' => $product->sku,
            'unit_of_measure' => $product->unit_of_measure->value,
            'true_cost' => '12.00',
            'markup_percent' => '25',
            'list_price' => '20.00',
            'is_active' => true,
            'vendor_id' => null,
            'product_category_id' => null,
            'related_product_ids' => [],
            'description' => null,
            'vendor_sku' => null,
            'notes' => null,
        ])
        ->assertRedirect();

    expect($product->fresh()->name)->toBe('Catalog Updated');
});

test('product resources omit true cost without permission under tenant', function () {
    $fixture = createTenantUser('salesperson');

    $product = Product::factory()->create([
        'parent_account_id' => $fixture['parent']->id,
    ]);

    $this->actingAs($fixture['user'])
        ->get(route('org.products.show', [$fixture['organization'], $product]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('product.true_cost')
            ->missing('product.markup_percent'));
});

test('explicit allow for view_cost exposes cost fields', function () {
    $fixture = createTenantUser('salesperson');
    attachOrgOverride($fixture['membership'], 'catalog.product.view_cost', PermissionEffect::Allow);

    $product = Product::factory()->create([
        'parent_account_id' => $fixture['parent']->id,
    ]);

    $this->actingAs($fixture['user'])
        ->get(route('org.products.show', [$fixture['organization'], $product]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('product.true_cost'));
});
