<?php

use App\Enums\MembershipStatus;
use App\Enums\OverheadMode;
use App\Enums\PricingMethod;
use App\Enums\ProductFamily;
use App\Enums\UnitOfMeasure;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\Product;
use App\Models\Role;
use App\Support\Money;
use App\Support\Tenancy\RoleAssigner;
use Illuminate\Support\Facades\DB;

function organizationProductPayload(array $overrides = []): array
{
    return [
        'name' => '48x96 ACM Sign 3MM',
        'sku' => 'ACM-4896-3',
        'product_family' => ProductFamily::Signage->value,
        'unit_of_measure' => UnitOfMeasure::Each->value,
        'is_active' => true,
        'is_available' => true,
        'material_cost' => '40',
        'labor_cost' => '30',
        'overhead_mode' => OverheadMode::Fixed->value,
        'overhead_amount' => '10',
        'overhead_rate_percent' => '0',
        'pricing_method' => PricingMethod::Markup->value,
        'markup_percent' => '50',
        'target_margin_percent' => '0',
        'fixed_price' => null,
        'minimum_price' => null,
        'allow_price_override' => false,
        ...$overrides,
    ];
}

test('creating a product master creates organization product only for current organization', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $otherOrg = Organization::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->post(route('org.products.store', $ctx['organization']), organizationProductPayload())
        ->assertRedirect();

    $product = Product::query()->where('sku', 'ACM-4896-3')->first();
    expect($product)->not->toBeNull()
        ->and($product->true_cost_micro_units)->toBe(0)
        ->and($product->list_price_cents)->toBeNull();

    $op = OrganizationProduct::query()->where('product_id', $product->id)->get();
    expect($op)->toHaveCount(1)
        ->and($op->first()->organization_id)->toBe($ctx['organization']->id)
        ->and($op->first()->material_cost_micro_units)->toBe(Money::dollarsToMicroUnits('40'))
        ->and(OrganizationProduct::query()->where('organization_id', $otherOrg->id)->count())->toBe(0);

    expect(AuditEvent::query()->where('action', 'catalog.product_master.created')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'catalog.organization_product.created')->count())->toBe(1);
});

test('transaction rollback leaves no master organization product or audit', function () {
    $ctx = createTenantUser('owner', 'parent_owner');

    DB::listen(function ($query): void {
        if (str_contains(strtolower($query->sql), 'insert into')
            && str_contains(strtolower($query->sql), 'audit_events')) {
            throw new RuntimeException('Induced audit failure');
        }
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($ctx['user'])
        ->post(route('org.products.store', $ctx['organization']), organizationProductPayload([
            'sku' => 'ROLLBACK-1',
        ])))->toThrow(RuntimeException::class, 'Induced audit failure');

    expect(Product::query()->where('sku', 'ROLLBACK-1')->exists())->toBeFalse()
        ->and(OrganizationProduct::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'like', 'catalog.%')->count())->toBe(0);
});

test('existing master can be associated without duplication and defaults unavailable without pricing', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $product = Product::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'is_active' => true,
    ]);

    $this->actingAs($ctx['user'])
        ->post(route('org.products.associate', $ctx['organization']), [
            'product_id' => $product->id,
            'include_pricing' => false,
        ])
        ->assertRedirect();

    $op = OrganizationProduct::query()->where('product_id', $product->id)->first();
    expect($op)->not->toBeNull()
        ->and($op->is_available)->toBeFalse()
        ->and(Product::query()->where('sku', $product->sku)->count())->toBe(1);

    $this->actingAs($ctx['user'])
        ->post(route('org.products.associate', $ctx['organization']), [
            'product_id' => $product->id,
            'include_pricing' => false,
        ])
        ->assertSessionHasErrors('product_id');
});

test('cross-parent master association is rejected', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $foreign = Product::factory()->create();

    $this->actingAs($ctx['user'])
        ->post(route('org.products.associate', $ctx['organization']), [
            'product_id' => $foreign->id,
            'include_pricing' => false,
        ])
        ->assertSessionHasErrors('product_id');
});

test('cross-organization organization product binding returns 404', function () {
    $pelican = createTenantUser('owner', 'parent_owner');
    $brim = Organization::factory()->create([
        'parent_account_id' => $pelican['parent']->id,
    ]);

    $product = Product::factory()->create([
        'parent_account_id' => $pelican['parent']->id,
    ]);
    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $pelican['parent']->id,
        'organization_id' => $pelican['organization']->id,
        'product_id' => $product->id,
    ]);

    $this->actingAs($pelican['user'])
        ->get(route('org.products.show', [$brim, $op]))
        ->assertNotFound();
});

test('pelican and brim may hold different pricing for one master', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $brim = Organization::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
    ]);
    $brimMembership = Membership::factory()->create([
        'organization_id' => $brim->id,
        'user_id' => $ctx['user']->id,
        'status' => MembershipStatus::Active,
    ]);
    app(RoleAssigner::class)->assignToOrganizationMembership(
        $brimMembership,
        Role::query()->where('key', 'owner')->firstOrFail(),
    );

    $this->actingAs($ctx['user'])
        ->post(route('org.products.store', $ctx['organization']), organizationProductPayload([
            'sku' => 'SHARED-1',
            'markup_percent' => '50',
        ]))
        ->assertRedirect();

    $product = Product::query()->where('sku', 'SHARED-1')->firstOrFail();

    $this->actingAs($ctx['user'])
        ->post(route('org.products.associate', $brim), [
            'product_id' => $product->id,
            'include_pricing' => true,
            'material_cost' => '40',
            'labor_cost' => '30',
            'overhead_mode' => OverheadMode::Fixed->value,
            'overhead_amount' => '10',
            'pricing_method' => PricingMethod::TargetMargin->value,
            'target_margin_percent' => '40',
            'markup_percent' => '0',
            'is_available' => true,
        ])
        ->assertRedirect();

    $pelicanOp = OrganizationProduct::query()
        ->where('organization_id', $ctx['organization']->id)
        ->where('product_id', $product->id)
        ->firstOrFail();
    $brimOp = OrganizationProduct::query()
        ->where('organization_id', $brim->id)
        ->where('product_id', $product->id)
        ->firstOrFail();

    expect($pelicanOp->pricing_method)->toBe(PricingMethod::Markup)
        ->and($brimOp->pricing_method)->toBe(PricingMethod::TargetMargin);

    $this->actingAs($ctx['user'])
        ->get(route('org.products.show', [$ctx['organization'], $pelicanOp]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('product.unit_selling_price', '120.00')
            ->where('product.material_cost', '40.0000'));

    $this->actingAs($ctx['user'])
        ->get(route('org.products.show', [$brim, $brimOp]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('product.unit_selling_price', '133.33')
            ->where('product.pricing_method', 'target_margin'));
});

test('cost fields are omitted without cost permission', function () {
    $sales = createTenantUser('salesperson');
    $salesOp = OrganizationProduct::factory()->create([
        'parent_account_id' => $sales['parent']->id,
        'organization_id' => $sales['organization']->id,
        'material_cost_micro_units' => Money::dollarsToMicroUnits('40'),
        'labor_cost_micro_units' => Money::dollarsToMicroUnits('30'),
        'overhead_mode' => OverheadMode::Fixed,
        'overhead_amount_micro_units' => Money::dollarsToMicroUnits('10'),
        'pricing_method' => PricingMethod::Markup,
        'markup_basis_points' => 5000,
    ]);

    $this->actingAs($sales['user'])
        ->get(route('org.products.show', [$sales['organization'], $salesOp]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('products/Show')
            ->where('canViewCost', false)
            ->missing('product.material_cost')
            ->missing('product.markup_percent')
            ->where('product.unit_selling_price', '120.00'));
});

test('pricing preview performs no write and matches calculator', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $beforeAudits = AuditEvent::query()->count();
    $beforeProducts = Product::query()->count();

    $this->actingAs($ctx['user'])
        ->postJson(route('org.products.pricing-preview', $ctx['organization']), [
            'material_cost' => '40',
            'labor_cost' => '30',
            'overhead_mode' => OverheadMode::Fixed->value,
            'overhead_amount' => '10',
            'pricing_method' => PricingMethod::Markup->value,
            'markup_percent' => '50',
            'quantity' => '2',
        ])
        ->assertOk()
        ->assertJsonPath('unit_selling_price', '120.00')
        ->assertJsonPath('extended_selling_price', '240.00');

    expect(AuditEvent::query()->count())->toBe($beforeAudits)
        ->and(Product::query()->count())->toBe($beforeProducts)
        ->and(OrganizationProduct::query()->count())->toBe(0);
});

test('catalog pricing rejects calculated price below minimum', function () {
    $ctx = createTenantUser('owner', 'parent_owner');

    $this->actingAs($ctx['user'])
        ->post(route('org.products.store', $ctx['organization']), organizationProductPayload([
            'sku' => 'MIN-1',
            'minimum_price' => '200.00',
        ]))
        ->assertSessionHasErrors('minimum_price');
});

test('legacy product mutations remain 409', function () {
    $ctx = createTenantUser('owner', 'parent_owner');

    $this->actingAs($ctx['user'])
        ->postJson(route('products.store'), organizationProductPayload())
        ->assertStatus(409);
});
