<?php

use App\Enums\OverheadMode;
use App\Enums\PermissionEffect;
use App\Enums\PricingMethod;
use App\Models\OrganizationProduct;
use App\Models\Product;
use App\Support\Tenancy\RbacDefinitions;

test('owner receives all six organization product permissions', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $keys = [
        'catalog.org_product.manage',
        'catalog.org_product.manage_pricing',
        'catalog.org_product.override_price',
        'catalog.org_product.override_margin',
        'catalog.org_product.approve_below_minimum',
        'catalog.org_product.archive',
    ];

    foreach ($keys as $key) {
        expect($ctx['membership']->roles->first()->permissions->pluck('key'))->toContain($key);
    }
});

test('sales manager receives only override and approval permissions', function () {
    $ctx = createTenantUser('sales_manager');
    $perms = $ctx['membership']->roles->first()->permissions->pluck('key');

    expect($perms)->toContain('catalog.org_product.override_price')
        ->and($perms)->toContain('catalog.org_product.override_margin')
        ->and($perms)->toContain('catalog.org_product.approve_below_minimum')
        ->and($perms)->not->toContain('catalog.org_product.manage')
        ->and($perms)->not->toContain('catalog.org_product.manage_pricing')
        ->and($perms)->not->toContain('catalog.org_product.archive');
});

test('finance receives pricing management but not below-minimum approval', function () {
    $ctx = createTenantUser('finance');
    $perms = $ctx['membership']->roles->first()->permissions->pluck('key');

    expect($perms)->toContain('catalog.org_product.manage_pricing')
        ->and($perms)->not->toContain('catalog.org_product.approve_below_minimum')
        ->and($perms)->not->toContain('catalog.org_product.manage')
        ->and($perms)->not->toContain('catalog.org_product.archive');
});

test('salesperson receives none of the new management permissions', function () {
    $ctx = createTenantUser('salesperson');
    $perms = $ctx['membership']->roles->first()->permissions->pluck('key');

    foreach ([
        'catalog.org_product.manage',
        'catalog.org_product.manage_pricing',
        'catalog.org_product.override_price',
        'catalog.org_product.override_margin',
        'catalog.org_product.approve_below_minimum',
        'catalog.org_product.archive',
    ] as $key) {
        expect($perms)->not->toContain($key);
    }
});

test('parent permission is required for master mutation', function () {
    $ctx = createTenantUser('owner'); // org owner without parent role
    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->patch(route('org.products.update-master', [$ctx['organization'], $op]), [
            'name' => 'Changed',
            'sku' => $op->product->sku,
            'product_family' => $op->product->product_family->value,
            'item_kind' => $op->product->item_kind->value,
            'unit_of_measure' => $op->product->unit_of_measure->value,
        ])
        ->assertForbidden();
});

test('pricing manage plus cost permission required for pricing updates', function () {
    $ctx = createTenantUser('salesperson');
    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'pricing_version' => 1,
    ]);

    $this->actingAs($ctx['user'])
        ->patch(route('org.products.update-pricing', [$ctx['organization'], $op]), [
            'pricing_version' => 1,
            'material_cost' => '10',
            'labor_cost' => '10',
            'overhead_mode' => OverheadMode::None->value,
            'pricing_method' => PricingMethod::Markup->value,
            'markup_percent' => '10',
        ])
        ->assertForbidden();
});

test('archive permission is required', function () {
    $ctx = createTenantUser('salesperson');
    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'is_available' => true,
    ]);

    $this->actingAs($ctx['user'])
        ->post(route('org.products.archive', [$ctx['organization'], $op]))
        ->assertForbidden();
});

test('deny overrides still win for organization product manage', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    attachOrgOverride($ctx['membership'], 'catalog.org_product.manage', PermissionEffect::Deny);

    $product = Product::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->post(route('org.products.associate', $ctx['organization']), [
            'product_id' => $product->id,
            'include_pricing' => false,
        ])
        ->assertForbidden();
});

test('rbac definitions include the six new organization product permissions', function () {
    $keys = collect(RbacDefinitions::permissions())->pluck('key');

    expect($keys)->toContain('catalog.org_product.manage')
        ->and($keys)->toContain('catalog.org_product.manage_pricing')
        ->and($keys)->toContain('catalog.org_product.override_price')
        ->and($keys)->toContain('catalog.org_product.override_margin')
        ->and($keys)->toContain('catalog.org_product.approve_below_minimum')
        ->and($keys)->toContain('catalog.org_product.archive');
});
