<?php

use App\Enums\OverheadMode;
use App\Enums\PricingMethod;
use App\Enums\ProductFamily;
use App\Enums\UnitOfMeasure;
use App\Models\OrganizationProduct;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\Money;

test('owners can create organization products with calculated selling price', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $category = ProductCategory::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->post(route('org.products.store', $ctx['organization']), [
            'name' => '48x96 ACM Sign 3MM',
            'sku' => 'ACM-4896-3',
            'product_family' => ProductFamily::Signage->value,
            'item_kind' => 'product',
            'vendor_sku' => 'VEN-ACM-1',
            'product_category_id' => $category->id,
            'unit_of_measure' => UnitOfMeasure::Each->value,
            'is_active' => true,
            'is_available' => true,
            'material_cost' => '40',
            'labor_cost' => '30',
            'overhead_mode' => OverheadMode::Fixed->value,
            'overhead_amount' => '10',
            'pricing_method' => PricingMethod::Markup->value,
            'markup_percent' => '50',
        ])
        ->assertRedirect();

    $product = Product::query()->where('sku', 'ACM-4896-3')->first();
    $op = OrganizationProduct::query()->where('product_id', $product->id)->first();

    expect($product)->not->toBeNull()
        ->and($op)->not->toBeNull()
        ->and($op->material_cost_micro_units)->toBe(Money::dollarsToMicroUnits('40'))
        ->and($op->markup_basis_points)->toBe(5000)
        ->and($product->true_cost_micro_units)->toBe(0);
});

test('salesmen can view organization products but cannot create them', function () {
    $ctx = createTenantUser('salesperson');
    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->get(route('org.products.index', $ctx['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('products/Index')
            ->where('canViewCost', false)
            ->where('canCreate', false));

    $this->actingAs($ctx['user'])
        ->get(route('org.products.show', [$ctx['organization'], $op]))
        ->assertOk();

    $this->actingAs($ctx['user'])
        ->post(route('org.products.store', $ctx['organization']), [
            'name' => 'Nope',
            'sku' => 'NOPE-1',
            'product_family' => ProductFamily::Other->value,
            'item_kind' => 'product',
            'unit_of_measure' => UnitOfMeasure::Each->value,
            'material_cost' => '10',
            'labor_cost' => '0',
            'overhead_mode' => OverheadMode::None->value,
            'pricing_method' => PricingMethod::Markup->value,
            'markup_percent' => '10',
        ])
        ->assertForbidden();
});

test('salesmen cannot see organization product cost fields', function () {
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
            ->where('product.unit_selling_price', '120.00'));
});
