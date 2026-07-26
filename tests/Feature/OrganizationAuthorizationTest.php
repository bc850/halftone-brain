<?php

use App\Enums\PermissionEffect;
use App\Models\Company;
use App\Models\OrganizationCompany;
use App\Models\OrganizationProduct;

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

test('parent catalog manager with org admin can update product masters', function () {
    $fixture = createTenantUser('admin', 'parent_catalog_manager');

    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $fixture['parent']->id,
        'organization_id' => $fixture['organization']->id,
    ]);
    $product = $op->product;

    $this->actingAs($fixture['user'])
        ->patch(route('org.products.update-master', [$fixture['organization'], $op]), [
            'name' => 'Catalog Updated',
            'sku' => $product->sku,
            'product_family' => $product->product_family->value,
            'item_kind' => $product->item_kind->value,
            'unit_of_measure' => $product->unit_of_measure->value,
            'is_active' => true,
            'product_category_id' => null,
            'description' => null,
            'notes' => null,
        ])
        ->assertRedirect();

    expect($product->fresh()->name)->toBe('Catalog Updated');
});

test('product resources omit cost without permission under tenant', function () {
    $fixture = createTenantUser('salesperson');

    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $fixture['parent']->id,
        'organization_id' => $fixture['organization']->id,
        'material_cost_micro_units' => 400_000,
        'markup_basis_points' => 5000,
    ]);

    $this->actingAs($fixture['user'])
        ->get(route('org.products.show', [$fixture['organization'], $op]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('product.material_cost')
            ->missing('product.markup_percent'));
});

test('explicit allow for view_cost exposes cost fields', function () {
    $fixture = createTenantUser('salesperson');
    attachOrgOverride($fixture['membership'], 'catalog.product.view_cost', PermissionEffect::Allow);

    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $fixture['parent']->id,
        'organization_id' => $fixture['organization']->id,
        'material_cost_micro_units' => 400_000,
    ]);

    $this->actingAs($fixture['user'])
        ->get(route('org.products.show', [$fixture['organization'], $op]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('product.material_cost'));
});
