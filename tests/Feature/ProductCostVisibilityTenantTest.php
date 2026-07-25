<?php

use App\Enums\OverheadMode;
use App\Enums\PermissionEffect;
use App\Enums\PricingMethod;
use App\Enums\UserRole;
use App\Models\OrganizationProduct;
use App\Support\Money;

test('owner sees organization product cost under tenant context', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'material_cost_micro_units' => Money::dollarsToMicroUnits('40'),
        'labor_cost_micro_units' => Money::dollarsToMicroUnits('30'),
        'overhead_mode' => OverheadMode::Fixed,
        'overhead_amount_micro_units' => Money::dollarsToMicroUnits('10'),
        'pricing_method' => PricingMethod::Markup,
        'markup_basis_points' => 5000,
    ]);

    $this->actingAs($ctx['user'])
        ->get(route('org.products.show', [$ctx['organization'], $op]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('product.material_cost')
            ->where('canViewCost', true));

    $this->actingAs($ctx['user'])
        ->get(route('org.products.index', $ctx['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canViewCost', true));
});

test('salesperson without cost permission does not receive cost fields', function () {
    $ctx = createTenantUser('salesperson');
    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'material_cost_micro_units' => Money::dollarsToMicroUnits('40'),
        'labor_cost_micro_units' => Money::dollarsToMicroUnits('30'),
        'overhead_mode' => OverheadMode::Fixed,
        'overhead_amount_micro_units' => Money::dollarsToMicroUnits('10'),
        'pricing_method' => PricingMethod::Markup,
        'markup_basis_points' => 5000,
    ]);

    $this->actingAs($ctx['user'])
        ->get(route('org.products.show', [$ctx['organization'], $op]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('product.material_cost')
            ->missing('product.markup_percent')
            ->where('canViewCost', false));

    $this->actingAs($ctx['user'])
        ->get(route('org.products.index', $ctx['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canViewCost', false)
            ->missing('products.data.0.material_cost'));
});

test('legacy isAdmin does not grant tenant cost access by itself', function () {
    $ctx = createTenantUser('salesperson');
    $ctx['user']->forceFill(['role' => UserRole::Admin])->save();

    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'material_cost_micro_units' => Money::dollarsToMicroUnits('40'),
        'labor_cost_micro_units' => 0,
        'overhead_mode' => OverheadMode::None,
        'pricing_method' => PricingMethod::Markup,
        'markup_basis_points' => 5000,
    ]);

    $this->actingAs($ctx['user']->fresh())
        ->get(route('org.products.show', [$ctx['organization'], $op]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('product.material_cost')
            ->where('canViewCost', false));

    $this->actingAs($ctx['user']->fresh())
        ->get(route('org.products.index', $ctx['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canViewCost', false));
});

test('org user with explicit cost permission sees cost', function () {
    $ctx = createTenantUser('salesperson');
    attachOrgOverride($ctx['membership'], 'catalog.product.view_cost', PermissionEffect::Allow);

    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'material_cost_micro_units' => Money::dollarsToMicroUnits('40'),
        'labor_cost_micro_units' => 0,
        'overhead_mode' => OverheadMode::None,
        'pricing_method' => PricingMethod::Markup,
        'markup_basis_points' => 5000,
    ]);

    $this->actingAs($ctx['user'])
        ->get(route('org.products.show', [$ctx['organization'], $op]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('product.material_cost')
            ->where('canViewCost', true));
});

test('parent cost permission grants cost under tenant context', function () {
    $ctx = createTenantUser('salesperson', 'parent_owner');
    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'material_cost_micro_units' => Money::dollarsToMicroUnits('40'),
        'labor_cost_micro_units' => 0,
        'overhead_mode' => OverheadMode::None,
        'pricing_method' => PricingMethod::Markup,
        'markup_basis_points' => 5000,
    ]);

    $this->actingAs($ctx['user'])
        ->get(route('org.products.show', [$ctx['organization'], $op]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('product.material_cost')
            ->where('canViewCost', true));

    $this->actingAs($ctx['user'])
        ->get(route('org.products.index', $ctx['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canViewCost', true));
});
