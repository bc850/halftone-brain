<?php

use App\Enums\InventoryTrackingMode;
use App\Enums\ItemKind;
use App\Enums\MembershipStatus;
use App\Enums\OverheadMode;
use App\Enums\PricingMethod;
use App\Enums\ProductFamily;
use App\Enums\UnitOfMeasure;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\OrganizationProductUnitConversion;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\Catalog\IncompleteUnitSetup;
use App\Support\Catalog\UnitConversionPreview;
use App\Support\Money;
use App\Support\Tenancy\RoleAssigner;

function phase1c5SellablePayload(array $overrides = []): array
{
    return [
        'name' => 'Finished Sign',
        'sku' => 'SIGN-'.fake()->unique()->numerify('####'),
        'product_family' => ProductFamily::Signage->value,
        'item_kind' => ItemKind::Product->value,
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

function phase1c5MaterialPayload(array $overrides = []): array
{
    return phase1c5SellablePayload([
        'name' => '4x8 3mm ACM Sheet',
        'sku' => 'ACM-'.fake()->unique()->numerify('####'),
        'item_kind' => ItemKind::Material->value,
        'unit_of_measure' => UnitOfMeasure::Sheet->value,
        'material_cost' => null,
        'labor_cost' => null,
        'overhead_mode' => null,
        'overhead_amount' => null,
        'pricing_method' => null,
        'markup_percent' => null,
        ...$overrides,
    ]);
}

test('classification defaults apply for product material and service', function (string $kind, bool $sellable, bool $purchasable, string $mode) {
    $ctx = createTenantUser('owner', 'parent_owner');

    $payload = $kind === ItemKind::Material->value
        ? phase1c5MaterialPayload(['item_kind' => $kind, 'sku' => 'DEF-'.$kind])
        : phase1c5SellablePayload([
            'item_kind' => $kind,
            'sku' => 'DEF-'.$kind,
            'name' => 'Kind '.$kind,
            'unit_of_measure' => $kind === ItemKind::Service->value ? UnitOfMeasure::Hour->value : UnitOfMeasure::Each->value,
            'product_family' => $kind === ItemKind::Service->value ? ProductFamily::Service->value : ProductFamily::Signage->value,
        ]);

    $this->actingAs($ctx['user'])
        ->post(route('org.products.store', $ctx['organization']), $payload)
        ->assertRedirect();

    $product = Product::query()->where('sku', 'DEF-'.$kind)->firstOrFail();
    $op = OrganizationProduct::query()->where('product_id', $product->id)->firstOrFail();

    expect($product->item_kind->value)->toBe($kind)
        ->and($op->is_sellable)->toBe($sellable)
        ->and($op->is_purchasable)->toBe($purchasable)
        ->and($op->inventory_tracking_mode->value)->toBe($mode);
})->with([
    'product' => [ItemKind::Product->value, true, false, InventoryTrackingMode::None->value],
    'material' => [ItemKind::Material->value, false, true, InventoryTrackingMode::PeriodicExternal->value],
    'service' => [ItemKind::Service->value, true, false, InventoryTrackingMode::None->value],
]);

test('user can override classification defaults on create', function () {
    $ctx = createTenantUser('owner', 'parent_owner');

    $this->actingAs($ctx['user'])
        ->post(route('org.products.store', $ctx['organization']), phase1c5MaterialPayload([
            'sku' => 'MAT-OVERRIDE',
            'is_sellable' => true,
            'is_purchasable' => true,
            'inventory_tracking_mode' => InventoryTrackingMode::PeriodicExternal->value,
            'material_cost' => '80',
            'labor_cost' => '0',
            'overhead_mode' => OverheadMode::None->value,
            'pricing_method' => PricingMethod::Markup->value,
            'markup_percent' => '25',
        ]))
        ->assertRedirect();

    $op = OrganizationProduct::query()->whereHas('product', fn ($q) => $q->where('sku', 'MAT-OVERRIDE'))->firstOrFail();

    expect($op->is_sellable)->toBeTrue()
        ->and($op->is_purchasable)->toBeTrue()
        ->and($op->inventory_tracking_mode)->toBe(InventoryTrackingMode::PeriodicExternal)
        ->and($op->material_cost_micro_units)->toBe(Money::dollarsToMicroUnits('80'));
});

test('sellable creation requires valid pricing', function () {
    $ctx = createTenantUser('owner', 'parent_owner');

    $this->actingAs($ctx['user'])
        ->post(route('org.products.store', $ctx['organization']), phase1c5SellablePayload([
            'sku' => 'NEED-PRICE',
            'material_cost' => null,
            'labor_cost' => null,
            'overhead_mode' => null,
            'pricing_method' => null,
        ]))
        ->assertSessionHasErrors(['material_cost', 'labor_cost', 'overhead_mode', 'pricing_method']);
});

test('non-sellable material can be created without pricing', function () {
    $ctx = createTenantUser('owner', 'parent_owner');

    $this->actingAs($ctx['user'])
        ->post(route('org.products.store', $ctx['organization']), phase1c5MaterialPayload([
            'sku' => 'MAT-NOPRICE',
            'purchase_unit_of_measure' => UnitOfMeasure::Sheet->value,
            'stock_unit_of_measure' => UnitOfMeasure::Sheet->value,
            'usage_unit_of_measure' => UnitOfMeasure::SquareFoot->value,
        ]))
        ->assertRedirect();

    $op = OrganizationProduct::query()->whereHas('product', fn ($q) => $q->where('sku', 'MAT-NOPRICE'))->firstOrFail();

    expect($op->is_sellable)->toBeFalse()
        ->and($op->material_cost_micro_units)->toBe(0)
        ->and($op->purchase_unit_of_measure)->toBe(UnitOfMeasure::Sheet)
        ->and($op->usage_unit_of_measure)->toBe(UnitOfMeasure::SquareFoot);
});

test('service rejects periodic external inventory tracking', function () {
    $ctx = createTenantUser('owner', 'parent_owner');

    $this->actingAs($ctx['user'])
        ->post(route('org.products.store', $ctx['organization']), phase1c5SellablePayload([
            'sku' => 'SVC-INV',
            'name' => 'Install',
            'item_kind' => ItemKind::Service->value,
            'product_family' => ProductFamily::Service->value,
            'unit_of_measure' => UnitOfMeasure::Hour->value,
            'is_purchasable' => true,
            'inventory_tracking_mode' => InventoryTrackingMode::PeriodicExternal->value,
        ]))
        ->assertSessionHasErrors('inventory_tracking_mode');
});

test('periodic external requires purchasable', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'is_sellable' => true,
        'is_purchasable' => false,
        'inventory_tracking_mode' => InventoryTrackingMode::None,
    ]);

    $this->actingAs($ctx['user'])
        ->patch(route('org.products.update-settings', [$ctx['organization'], $op]), [
            'display_name' => null,
            'is_available' => true,
            'is_sellable' => true,
            'is_purchasable' => false,
            'inventory_tracking_mode' => InventoryTrackingMode::PeriodicExternal->value,
            'notes' => null,
        ])
        ->assertSessionHasErrors('inventory_tracking_mode');
});

test('master kind change does not mutate organization settings', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $product = Product::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'item_kind' => ItemKind::Product,
    ]);
    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'product_id' => $product->id,
        'is_sellable' => true,
        'is_purchasable' => false,
        'inventory_tracking_mode' => InventoryTrackingMode::None,
        'purchase_unit_of_measure' => UnitOfMeasure::Each,
    ]);

    $this->actingAs($ctx['user'])
        ->patch(route('org.products.update-master', [$ctx['organization'], $op]), [
            'name' => $product->name,
            'sku' => $product->sku,
            'product_family' => $product->product_family->value,
            'item_kind' => ItemKind::Material->value,
            'unit_of_measure' => $product->unit_of_measure->value,
            'is_active' => true,
        ])
        ->assertRedirect();

    $op->refresh();
    $product->refresh();

    expect($product->item_kind)->toBe(ItemKind::Material)
        ->and($op->is_sellable)->toBeTrue()
        ->and($op->is_purchasable)->toBeFalse()
        ->and($op->inventory_tracking_mode)->toBe(InventoryTrackingMode::None)
        ->and($op->purchase_unit_of_measure)->toBe(UnitOfMeasure::Each);
});

test('org admin without parent manage cannot edit master classification', function () {
    $ctx = createTenantUser('owner');
    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->patch(route('org.products.update-master', [$ctx['organization'], $op]), [
            'name' => 'Nope',
            'sku' => $op->product->sku,
            'product_family' => $op->product->product_family->value,
            'item_kind' => ItemKind::Material->value,
            'unit_of_measure' => $op->product->unit_of_measure->value,
        ])
        ->assertForbidden();
});

test('non-sellable materials remain visible in catalog and filters work', function () {
    $ctx = createTenantUser('owner', 'parent_owner');

    $this->actingAs($ctx['user'])
        ->post(route('org.products.store', $ctx['organization']), phase1c5MaterialPayload(['sku' => 'MAT-VISIBLE']))
        ->assertRedirect();

    $this->actingAs($ctx['user'])
        ->get(route('org.products.index', $ctx['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('products/Index')
            ->has('products.data', 1)
            ->where('products.data.0.product.item_kind', ItemKind::Material->value)
            ->where('products.data.0.is_sellable', false));

    $this->actingAs($ctx['user'])
        ->get(route('org.products.index', [
            'organization' => $ctx['organization'],
            'item_kind' => ItemKind::Material->value,
            'is_purchasable' => '1',
            'inventory_tracking_mode' => InventoryTrackingMode::PeriodicExternal->value,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('products.data', 1));

    $this->actingAs($ctx['user'])
        ->get(route('org.products.index', [
            'organization' => $ctx['organization'],
            'item_kind' => ItemKind::Service->value,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('products.data', 0));
});

test('conversion create update deactivate reactivate and audits', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'is_purchasable' => true,
        'inventory_tracking_mode' => InventoryTrackingMode::PeriodicExternal,
        'purchase_unit_of_measure' => UnitOfMeasure::Sheet,
        'stock_unit_of_measure' => UnitOfMeasure::Sheet,
        'usage_unit_of_measure' => UnitOfMeasure::SquareFoot,
    ]);

    $this->actingAs($ctx['user'])
        ->post(route('org.products.conversions.store', [$ctx['organization'], $op]), [
            'from_unit' => UnitOfMeasure::Sheet->value,
            'to_unit' => UnitOfMeasure::SquareFoot->value,
            'numerator' => 32,
            'denominator' => 1,
        ])
        ->assertRedirect();

    $conversion = OrganizationProductUnitConversion::query()->firstOrFail();
    expect($conversion->numerator)->toBe(32)
        ->and($conversion->denominator)->toBe(1)
        ->and(AuditEvent::query()->where('action', 'catalog.organization_product_unit_conversion.created')->count())->toBe(1);

    $this->actingAs($ctx['user'])
        ->patch(route('org.products.conversions.update', [$ctx['organization'], $op, $conversion]), [
            'from_unit' => UnitOfMeasure::Sheet->value,
            'to_unit' => UnitOfMeasure::SquareFoot->value,
            'numerator' => 30,
            'denominator' => 1,
        ])
        ->assertRedirect();

    expect($conversion->fresh()->numerator)->toBe(30)
        ->and(AuditEvent::query()->where('action', 'catalog.organization_product_unit_conversion.updated')->count())->toBe(1);

    $this->actingAs($ctx['user'])
        ->post(route('org.products.conversions.deactivate', [$ctx['organization'], $op, $conversion]))
        ->assertRedirect();
    expect($conversion->fresh()->is_active)->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'catalog.organization_product_unit_conversion.deactivated')->count())->toBe(1);

    $this->actingAs($ctx['user'])
        ->post(route('org.products.conversions.reactivate', [$ctx['organization'], $op, $conversion]))
        ->assertRedirect();
    expect($conversion->fresh()->is_active)->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'catalog.organization_product_unit_conversion.reactivated')->count())->toBe(1);
});

test('conversion rejects zero ratios same unit and duplicates', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->post(route('org.products.conversions.store', [$ctx['organization'], $op]), [
            'from_unit' => UnitOfMeasure::Sheet->value,
            'to_unit' => UnitOfMeasure::SquareFoot->value,
            'numerator' => 0,
            'denominator' => 1,
        ])
        ->assertSessionHasErrors('numerator');

    $this->actingAs($ctx['user'])
        ->post(route('org.products.conversions.store', [$ctx['organization'], $op]), [
            'from_unit' => UnitOfMeasure::Sheet->value,
            'to_unit' => UnitOfMeasure::SquareFoot->value,
            'numerator' => 1,
            'denominator' => 0,
        ])
        ->assertSessionHasErrors('denominator');

    $this->actingAs($ctx['user'])
        ->post(route('org.products.conversions.store', [$ctx['organization'], $op]), [
            'from_unit' => UnitOfMeasure::Sheet->value,
            'to_unit' => UnitOfMeasure::Sheet->value,
            'numerator' => 1,
            'denominator' => 1,
        ])
        ->assertSessionHasErrors('to_unit');

    $this->actingAs($ctx['user'])
        ->post(route('org.products.conversions.store', [$ctx['organization'], $op]), [
            'from_unit' => UnitOfMeasure::Sheet->value,
            'to_unit' => UnitOfMeasure::SquareFoot->value,
            'numerator' => 32,
            'denominator' => 1,
        ])
        ->assertRedirect();

    $this->actingAs($ctx['user'])
        ->post(route('org.products.conversions.store', [$ctx['organization'], $op]), [
            'from_unit' => UnitOfMeasure::Sheet->value,
            'to_unit' => UnitOfMeasure::SquareFoot->value,
            'numerator' => 32,
            'denominator' => 1,
        ])
        ->assertSessionHasErrors('from_unit');
});

test('exact conversion preview uses no floats', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->postJson(route('org.products.conversions.preview', [$ctx['organization'], $op]), [
            'from_unit' => UnitOfMeasure::Sheet->value,
            'to_unit' => UnitOfMeasure::SquareFoot->value,
            'numerator' => 32,
            'denominator' => 1,
        ])
        ->assertOk()
        ->assertJsonPath('preview', '1 Sheet = 32 Square foot')
        ->assertJsonPath('derived_reciprocal', 'Derived reciprocal (not stored): 1 Square foot = 0.03125 Sheet');

    $preview = UnitConversionPreview::make(
        UnitOfMeasure::Sheet->value,
        UnitOfMeasure::SquareFoot->value,
        32,
        1,
    );
    expect($preview['converted_one'])->toBe('32.00000000');
});

test('incomplete unit setup warning when conversion missing', function () {
    $op = OrganizationProduct::factory()->make([
        'purchase_unit_of_measure' => UnitOfMeasure::Sheet,
        'stock_unit_of_measure' => UnitOfMeasure::Sheet,
        'usage_unit_of_measure' => UnitOfMeasure::SquareFoot,
    ]);
    $op->setRelation('unitConversions', collect());

    expect(IncompleteUnitSetup::applies($op))->toBeTrue()
        ->and(IncompleteUnitSetup::warningMessage())->toContain('Unit setup is incomplete');

    $same = OrganizationProduct::factory()->make([
        'purchase_unit_of_measure' => UnitOfMeasure::Sheet,
        'stock_unit_of_measure' => UnitOfMeasure::Sheet,
        'usage_unit_of_measure' => UnitOfMeasure::Sheet,
    ]);
    $same->setRelation('unitConversions', collect());
    expect(IncompleteUnitSetup::applies($same))->toBeFalse();
});

test('cross organization conversion access returns 404', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $other = Organization::factory()->create(['parent_account_id' => $ctx['parent']->id]);
    $otherOp = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $other->id,
    ]);
    $conversion = OrganizationProductUnitConversion::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $other->id,
        'organization_product_id' => $otherOp->id,
    ]);

    $this->actingAs($ctx['user'])
        ->post(route('org.products.conversions.deactivate', [$ctx['organization'], $otherOp, $conversion]))
        ->assertNotFound();
});

test('show redacts costs for salesperson and settings warn for incomplete units', function () {
    $owner = createTenantUser('owner', 'parent_owner');

    $salesUser = User::factory()->create();
    $membership = Membership::factory()->create([
        'organization_id' => $owner['organization']->id,
        'user_id' => $salesUser->id,
        'status' => MembershipStatus::Active,
    ]);
    $salesRole = Role::query()->where('key', 'salesperson')->firstOrFail();
    app(RoleAssigner::class)->assignToOrganizationMembership($membership, $salesRole);

    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $owner['parent']->id,
        'organization_id' => $owner['organization']->id,
        'is_sellable' => true,
        'material_cost_micro_units' => Money::dollarsToMicroUnits('40'),
        'purchase_unit_of_measure' => UnitOfMeasure::Sheet,
        'usage_unit_of_measure' => UnitOfMeasure::SquareFoot,
    ]);

    $this->actingAs($salesUser)
        ->get(route('org.products.show', [$owner['organization'], $op]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('product.material_cost')
            ->where('canViewCost', false)
            ->where('product.unit_setup_incomplete', true));
});

test('legacy mutation remains 409 and stale pricing intact', function () {
    $ctx = createTenantUser('owner', 'parent_owner');

    $this->actingAs($ctx['user'])
        ->postJson(route('products.store'), phase1c5SellablePayload(['sku' => 'LEGACY-1C5']))
        ->assertStatus(409);

    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'pricing_version' => 2,
    ]);

    $this->actingAs($ctx['user'])
        ->patch(route('org.products.update-pricing', [$ctx['organization'], $op]), [
            'pricing_version' => 1,
            'components_version' => 1,
            'material_cost' => '10',
            'labor_cost' => '10',
            'overhead_mode' => OverheadMode::None->value,
            'pricing_method' => PricingMethod::Markup->value,
            'markup_percent' => '10',
        ])
        ->assertStatus(409);
});
