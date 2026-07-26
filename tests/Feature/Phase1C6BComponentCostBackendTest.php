<?php

use App\Enums\ItemKind;
use App\Enums\OverheadMode;
use App\Enums\PermissionEffect;
use App\Enums\PricingMethod;
use App\Enums\UnitOfMeasure;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\OrganizationProductComponent;
use App\Models\OrganizationProductUnitConversion;
use App\Models\Product;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Money;

/**
 * @return array{
 *     ctx: array<string, mixed>,
 *     finished: OrganizationProduct,
 *     material: OrganizationProduct,
 *     conversion: OrganizationProductUnitConversion
 * }
 */
function phase1c6bAcmGraph(): array
{
    $ctx = createTenantUser('owner', 'parent_owner');

    $finishedMaster = Product::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'item_kind' => ItemKind::Product,
        'name' => '48x96 ACM Sign',
        'sku' => 'ACM-SIGN-1C6B',
    ]);

    $materialMaster = Product::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'item_kind' => ItemKind::Material,
        'name' => '4x8 3mm ACM Sheet',
        'sku' => 'ACM-SHEET-1C6B',
        'unit_of_measure' => UnitOfMeasure::Sheet,
    ]);

    $finished = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'product_id' => $finishedMaster->id,
        'is_sellable' => true,
        'is_purchasable' => false,
        'material_cost_micro_units' => Money::dollarsToMicroUnits('1'),
        'labor_cost_micro_units' => 0,
        'overhead_mode' => OverheadMode::None,
        'pricing_method' => PricingMethod::Markup,
        'markup_basis_points' => 0,
        'pricing_version' => 1,
        'components_version' => 1,
    ]);

    $material = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'product_id' => $materialMaster->id,
        'is_sellable' => false,
        'is_purchasable' => true,
        'is_available' => true,
        'purchase_unit_of_measure' => UnitOfMeasure::Sheet,
        'purchase_cost_micro_units' => Money::dollarsToMicroUnits('80'),
        'pricing_version' => 1,
        'components_version' => 1,
    ]);

    $conversion = OrganizationProductUnitConversion::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'organization_product_id' => $material->id,
        'from_unit' => UnitOfMeasure::Sheet,
        'to_unit' => UnitOfMeasure::SquareFoot,
        'numerator' => 32,
        'denominator' => 1,
        'is_active' => true,
    ]);

    return compact('ctx', 'finished', 'material', 'conversion');
}

test('phase 1c6b purchase cost set clear and redaction', function () {
    $g = phase1c6bAcmGraph();
    $ctx = $g['ctx'];
    $material = $g['material'];

    $this->actingAs($ctx['user'])
        ->patch(route('org.products.update-purchase-cost', [$ctx['organization'], $material]), [
            'purchase_cost' => '80.0000',
        ])
        ->assertRedirect();

    expect($material->fresh()->purchase_cost_micro_units)->toBe(Money::dollarsToMicroUnits('80'))
        ->and(AuditEvent::query()->where('action', 'catalog.organization_product.purchase_cost_updated')->count())->toBe(1);

    $this->actingAs($ctx['user'])
        ->get(route('org.products.show', [$ctx['organization'], $material]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('product.purchase_cost', '80.0000')
            ->where('product.components_version', 1));

    $this->actingAs($ctx['user'])
        ->patch(route('org.products.update-purchase-cost', [$ctx['organization'], $material]), [
            'purchase_cost' => null,
        ])
        ->assertRedirect();

    expect($material->fresh()->purchase_cost_micro_units)->toBeNull();

    $sales = createTenantUser('salesperson');
    $foreignMaterial = OrganizationProduct::factory()->create([
        'parent_account_id' => $sales['parent']->id,
        'organization_id' => $sales['organization']->id,
        'is_purchasable' => true,
        'purchase_unit_of_measure' => UnitOfMeasure::Sheet,
        'purchase_cost_micro_units' => Money::dollarsToMicroUnits('12.34'),
    ]);

    $this->actingAs($sales['user'])
        ->get(route('org.products.show', [$sales['organization'], $foreignMaterial]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('product.purchase_cost')
            ->missing('product.material_cost')
            ->where('product.components_version', 1));
});

test('phase 1c6b purchase cost requires purchasable and purchase uom', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'is_purchasable' => false,
        'purchase_unit_of_measure' => null,
    ]);

    $this->actingAs($ctx['user'])
        ->patch(route('org.products.update-purchase-cost', [$ctx['organization'], $op]), [
            'purchase_cost' => '10',
        ])
        ->assertSessionHasErrors('purchase_cost');

    $op->update([
        'is_purchasable' => true,
        'purchase_unit_of_measure' => null,
    ]);

    $this->actingAs($ctx['user'])
        ->patch(route('org.products.update-purchase-cost', [$ctx['organization'], $op]), [
            'purchase_cost' => '10',
        ])
        ->assertSessionHasErrors('purchase_cost');
});

test('phase 1c6b component crud bumps version audits and rejects duplicates', function () {
    $g = phase1c6bAcmGraph();
    $ctx = $g['ctx'];
    $finished = $g['finished'];
    $material = $g['material'];

    $this->actingAs($ctx['user'])
        ->post(route('org.products.components.store', [$ctx['organization'], $finished]), [
            'components_version' => 1,
            'component_organization_product_id' => $material->id,
            'quantity' => '10',
            'usage_uom' => UnitOfMeasure::SquareFoot->value,
            'waste_basis_points' => 1000,
        ])
        ->assertRedirect();

    $component = OrganizationProductComponent::query()
        ->where('organization_product_id', $finished->id)
        ->firstOrFail();

    expect($finished->fresh()->components_version)->toBe(2)
        ->and($finished->fresh()->pricing_version)->toBe(1)
        ->and($component->quantity_scaled)->toBe(ComponentCostEstimator::quantityToScaled('10'))
        ->and($component->waste_basis_points)->toBe(1000)
        ->and(AuditEvent::query()->where('action', 'catalog.organization_product_component.created')->count())->toBe(1);

    $this->actingAs($ctx['user'])
        ->post(route('org.products.components.store', [$ctx['organization'], $finished]), [
            'components_version' => 2,
            'component_organization_product_id' => $material->id,
            'quantity' => '5',
            'usage_uom' => UnitOfMeasure::SquareFoot->value,
        ])
        ->assertSessionHasErrors('component_organization_product_id');

    $this->actingAs($ctx['user'])
        ->patch(route('org.products.components.update', [$ctx['organization'], $finished, $component]), [
            'components_version' => 2,
            'quantity' => '10',
            'usage_uom' => UnitOfMeasure::SquareFoot->value,
            'waste_basis_points' => 500,
        ])
        ->assertRedirect();

    expect($finished->fresh()->components_version)->toBe(3)
        ->and($component->fresh()->waste_basis_points)->toBe(500)
        ->and(AuditEvent::query()->where('action', 'catalog.organization_product_component.updated')->count())->toBe(1);

    $this->actingAs($ctx['user'])
        ->post(route('org.products.components.deactivate', [$ctx['organization'], $finished, $component]), [
            'components_version' => 3,
        ])
        ->assertRedirect();

    expect($component->fresh()->is_active)->toBeFalse()
        ->and($finished->fresh()->components_version)->toBe(4)
        ->and(OrganizationProductComponent::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'catalog.organization_product_component.deactivated')->count())->toBe(1);

    $this->actingAs($ctx['user'])
        ->post(route('org.products.components.reactivate', [$ctx['organization'], $finished, $component]), [
            'components_version' => 4,
        ])
        ->assertRedirect();

    expect($component->fresh()->is_active)->toBeTrue()
        ->and($finished->fresh()->components_version)->toBe(5)
        ->and(AuditEvent::query()->where('action', 'catalog.organization_product_component.reactivated')->count())->toBe(1);
});

test('phase 1c6b stale components version returns 409', function () {
    $g = phase1c6bAcmGraph();
    $ctx = $g['ctx'];
    $finished = $g['finished'];
    $material = $g['material'];

    $this->actingAs($ctx['user'])
        ->post(route('org.products.components.store', [$ctx['organization'], $finished]), [
            'components_version' => 1,
            'component_organization_product_id' => $material->id,
            'quantity' => '10',
            'usage_uom' => UnitOfMeasure::SquareFoot->value,
            'waste_basis_points' => 1000,
        ])
        ->assertRedirect();

    $this->actingAs($ctx['user'])
        ->post(route('org.products.components.store', [$ctx['organization'], $finished]), [
            'components_version' => 1,
            'component_organization_product_id' => $material->id,
            'quantity' => '1',
            'usage_uom' => UnitOfMeasure::SquareFoot->value,
        ])
        ->assertStatus(409);

    expect($finished->fresh()->components_version)->toBe(2);
});

test('phase 1c6b purchase cost and settings invalidate dependent finished versions', function () {
    $g = phase1c6bAcmGraph();
    $ctx = $g['ctx'];
    $finished = $g['finished'];
    $material = $g['material'];

    OrganizationProductComponent::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'organization_product_id' => $finished->id,
        'component_organization_product_id' => $material->id,
        'quantity_scaled' => ComponentCostEstimator::quantityToScaled('10'),
        'usage_uom' => UnitOfMeasure::SquareFoot,
        'waste_basis_points' => 1000,
        'is_active' => true,
    ]);
    $finished->update(['components_version' => 1]);

    $this->actingAs($ctx['user'])
        ->patch(route('org.products.update-purchase-cost', [$ctx['organization'], $material]), [
            'purchase_cost' => '90',
        ])
        ->assertRedirect();

    expect($finished->fresh()->components_version)->toBe(2)
        ->and($finished->fresh()->pricing_version)->toBe(1);

    $this->actingAs($ctx['user'])
        ->patch(route('org.products.update-settings', [$ctx['organization'], $material]), [
            'display_name' => $material->display_name,
            'is_available' => false,
            'is_sellable' => $material->is_sellable,
            'is_purchasable' => true,
            'inventory_tracking_mode' => $material->inventory_tracking_mode->value,
            'purchase_unit_of_measure' => UnitOfMeasure::Sheet->value,
            'stock_unit_of_measure' => $material->stock_unit_of_measure?->value,
            'usage_unit_of_measure' => $material->usage_unit_of_measure?->value,
        ])
        ->assertRedirect();

    expect($finished->fresh()->components_version)->toBe(3);
});

test('phase 1c6b pricing save reestimates acm to twenty seven fifty', function () {
    $g = phase1c6bAcmGraph();
    $ctx = $g['ctx'];
    $finished = $g['finished'];
    $material = $g['material'];

    $this->actingAs($ctx['user'])
        ->post(route('org.products.components.store', [$ctx['organization'], $finished]), [
            'components_version' => 1,
            'component_organization_product_id' => $material->id,
            'quantity' => '10',
            'usage_uom' => UnitOfMeasure::SquareFoot->value,
            'waste_basis_points' => 1000,
        ])
        ->assertRedirect();

    $finished = $finished->fresh();

    $this->actingAs($ctx['user'])
        ->patch(route('org.products.update-pricing', [$ctx['organization'], $finished]), [
            'pricing_version' => 1,
            'components_version' => $finished->components_version,
            'material_cost' => '999',
            'labor_cost' => '0',
            'overhead_mode' => OverheadMode::None->value,
            'pricing_method' => PricingMethod::Markup->value,
            'markup_percent' => '0',
        ])
        ->assertRedirect();

    $fresh = $finished->fresh();

    expect($fresh->material_cost_micro_units)->toBe(Money::dollarsToMicroUnits('27.50'))
        ->and($fresh->pricing_version)->toBe(2)
        ->and($fresh->components_version)->toBe($finished->components_version);

    $audit = AuditEvent::query()
        ->where('action', 'catalog.organization_product.pricing_updated')
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and(data_get($audit->after_json, 'material_source'))->toBe('components')
        ->and(data_get($audit->after_json, 'material_cost_micro_units'))->toBe(Money::dollarsToMicroUnits('27.50'));
});

test('phase 1c6b pricing preview reestimates without writes and stale versions 409', function () {
    $g = phase1c6bAcmGraph();
    $ctx = $g['ctx'];
    $finished = $g['finished'];
    $material = $g['material'];

    $this->actingAs($ctx['user'])
        ->post(route('org.products.components.store', [$ctx['organization'], $finished]), [
            'components_version' => 1,
            'component_organization_product_id' => $material->id,
            'quantity' => '10',
            'usage_uom' => UnitOfMeasure::SquareFoot->value,
            'waste_basis_points' => 1000,
        ])
        ->assertRedirect();

    $finished = $finished->fresh();
    $beforeAudits = AuditEvent::query()->count();
    $beforeMaterial = $finished->material_cost_micro_units;
    $beforePricingVersion = $finished->pricing_version;
    $beforeComponentsVersion = $finished->components_version;

    $this->actingAs($ctx['user'])
        ->postJson(route('org.products.pricing-preview', $ctx['organization']), [
            'organization_product_id' => $finished->id,
            'pricing_version' => $finished->pricing_version,
            'components_version' => $finished->components_version,
            'material_cost' => '999',
            'labor_cost' => '0',
            'overhead_mode' => OverheadMode::None->value,
            'pricing_method' => PricingMethod::Markup->value,
            'markup_percent' => '0',
        ])
        ->assertOk()
        ->assertJsonPath('material_cost', '27.5000')
        ->assertJsonPath('material_source', 'components')
        ->assertJsonPath('unit_cost', '27.5000');

    expect(AuditEvent::query()->count())->toBe($beforeAudits)
        ->and($finished->fresh()->material_cost_micro_units)->toBe($beforeMaterial)
        ->and($finished->fresh()->pricing_version)->toBe($beforePricingVersion)
        ->and($finished->fresh()->components_version)->toBe($beforeComponentsVersion);

    $this->actingAs($ctx['user'])
        ->postJson(route('org.products.pricing-preview', $ctx['organization']), [
            'organization_product_id' => $finished->id,
            'pricing_version' => $finished->pricing_version,
            'components_version' => $finished->components_version - 1,
            'material_cost' => '1',
            'labor_cost' => '0',
            'overhead_mode' => OverheadMode::None->value,
            'pricing_method' => PricingMethod::Markup->value,
            'markup_percent' => '0',
        ])
        ->assertStatus(409);

    $this->actingAs($ctx['user'])
        ->patch(route('org.products.update-pricing', [$ctx['organization'], $finished]), [
            'pricing_version' => $finished->pricing_version,
            'components_version' => $finished->components_version - 1,
            'material_cost' => '1',
            'labor_cost' => '0',
            'overhead_mode' => OverheadMode::None->value,
            'pricing_method' => PricingMethod::Markup->value,
            'markup_percent' => '0',
        ])
        ->assertStatus(409);
});

test('phase 1c6b cross organization component routes return 404', function () {
    $g = phase1c6bAcmGraph();
    $other = Organization::factory()->create(['parent_account_id' => $g['ctx']['parent']->id]);

    $this->actingAs($g['ctx']['user'])
        ->post(route('org.products.components.store', [$other, $g['finished']]), [
            'components_version' => 1,
            'component_organization_product_id' => $g['material']->id,
            'quantity' => '10',
            'usage_uom' => UnitOfMeasure::SquareFoot->value,
        ])
        ->assertNotFound();

    $this->actingAs($g['ctx']['user'])
        ->patch(route('org.products.update-purchase-cost', [$other, $g['material']]), [
            'purchase_cost' => '10',
        ])
        ->assertNotFound();
});

test('phase 1c6b manage components follows manage permission', function () {
    $g = phase1c6bAcmGraph();
    attachOrgOverride($g['ctx']['membership'], 'catalog.org_product.manage', PermissionEffect::Deny);

    $this->actingAs($g['ctx']['user'])
        ->post(route('org.products.components.store', [$g['ctx']['organization'], $g['finished']]), [
            'components_version' => 1,
            'component_organization_product_id' => $g['material']->id,
            'quantity' => '10',
            'usage_uom' => UnitOfMeasure::SquareFoot->value,
        ])
        ->assertForbidden();
});

test('phase 1c6b show exposes estimate stale and redacts component costs', function () {
    $g = phase1c6bAcmGraph();
    $ctx = $g['ctx'];
    $finished = $g['finished'];
    $material = $g['material'];

    OrganizationProductComponent::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'organization_product_id' => $finished->id,
        'component_organization_product_id' => $material->id,
        'quantity_scaled' => ComponentCostEstimator::quantityToScaled('10'),
        'usage_uom' => UnitOfMeasure::SquareFoot,
        'waste_basis_points' => 1000,
        'is_active' => true,
    ]);

    $finished->update([
        'material_cost_micro_units' => Money::dollarsToMicroUnits('1'),
        'components_version' => 2,
    ]);

    $this->actingAs($ctx['user'])
        ->get(route('org.products.show', [$ctx['organization'], $finished]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('product.components_version', 2)
            ->where('product.material_cost_source', 'components')
            ->where('product.estimate_stale', true)
            ->where('product.estimated_material_cost', '27.5000')
            ->where('product.components.0.material.purchase_cost', '80.0000'));

    $sales = createTenantUser('salesperson');
    $salesFinished = OrganizationProduct::factory()->create([
        'parent_account_id' => $sales['parent']->id,
        'organization_id' => $sales['organization']->id,
        'is_sellable' => true,
        'material_cost_micro_units' => Money::dollarsToMicroUnits('1'),
    ]);
    $salesMaterialMaster = Product::factory()->create([
        'parent_account_id' => $sales['parent']->id,
        'item_kind' => ItemKind::Material,
    ]);
    $salesMaterial = OrganizationProduct::factory()->create([
        'parent_account_id' => $sales['parent']->id,
        'organization_id' => $sales['organization']->id,
        'product_id' => $salesMaterialMaster->id,
        'is_purchasable' => true,
        'purchase_unit_of_measure' => UnitOfMeasure::Sheet,
        'purchase_cost_micro_units' => Money::dollarsToMicroUnits('80'),
    ]);
    OrganizationProductComponent::factory()->create([
        'parent_account_id' => $sales['parent']->id,
        'organization_id' => $sales['organization']->id,
        'organization_product_id' => $salesFinished->id,
        'component_organization_product_id' => $salesMaterial->id,
        'quantity_scaled' => ComponentCostEstimator::quantityToScaled('10'),
        'usage_uom' => UnitOfMeasure::SquareFoot,
        'is_active' => true,
    ]);

    $this->actingAs($sales['user'])
        ->get(route('org.products.show', [$sales['organization'], $salesFinished]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('product.material_cost_source', 'components')
            ->missing('product.material_cost')
            ->missing('product.estimated_material_cost')
            ->missing('product.components.0.material.purchase_cost'));
});

test('phase 1c6b unit conversion mutation invalidates dependents', function () {
    $g = phase1c6bAcmGraph();
    $ctx = $g['ctx'];
    $finished = $g['finished'];
    $material = $g['material'];
    $conversion = $g['conversion'];

    OrganizationProductComponent::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'organization_product_id' => $finished->id,
        'component_organization_product_id' => $material->id,
        'quantity_scaled' => ComponentCostEstimator::quantityToScaled('10'),
        'usage_uom' => UnitOfMeasure::SquareFoot,
        'is_active' => true,
    ]);
    $finished->update(['components_version' => 1]);

    $this->actingAs($ctx['user'])
        ->post(route('org.products.conversions.deactivate', [$ctx['organization'], $material, $conversion]))
        ->assertRedirect();

    expect($finished->fresh()->components_version)->toBe(2);
});

test('phase 1c6b create organization product defaults components version to one', function () {
    $ctx = createTenantUser('owner', 'parent_owner');

    $this->actingAs($ctx['user'])
        ->post(route('org.products.store', $ctx['organization']), [
            'name' => 'Default Components Version',
            'sku' => 'DEF-CV-1',
            'product_family' => 'signage',
            'item_kind' => ItemKind::Product->value,
            'unit_of_measure' => UnitOfMeasure::Each->value,
            'is_active' => true,
            'is_available' => true,
            'is_sellable' => true,
            'is_purchasable' => false,
            'inventory_tracking_mode' => 'none',
            'material_cost' => '10',
            'labor_cost' => '0',
            'overhead_mode' => OverheadMode::None->value,
            'pricing_method' => PricingMethod::Markup->value,
            'markup_percent' => '0',
        ])
        ->assertRedirect();

    $op = OrganizationProduct::query()->latest('id')->firstOrFail();

    expect($op->components_version)->toBe(1);
});

test('phase 1c6b rejects material parent and missing conversion', function () {
    $g = phase1c6bAcmGraph();
    $ctx = $g['ctx'];
    $material = $g['material'];

    $otherMaterialMaster = Product::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'item_kind' => ItemKind::Material,
        'sku' => 'ACM-OTHER-1C6B',
    ]);
    $otherMaterial = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'product_id' => $otherMaterialMaster->id,
        'is_purchasable' => true,
        'is_available' => true,
        'purchase_unit_of_measure' => UnitOfMeasure::Sheet,
        'purchase_cost_micro_units' => Money::dollarsToMicroUnits('50'),
    ]);

    $this->actingAs($ctx['user'])
        ->from(route('org.products.edit-pricing', [$ctx['organization'], $material]))
        ->post(route('org.products.components.store', [$ctx['organization'], $material]), [
            'component_organization_product_id' => $otherMaterial->id,
            'quantity' => '1',
            'usage_uom' => UnitOfMeasure::Sheet->value,
            'waste_basis_points' => 0,
            'components_version' => $material->components_version,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors();

    $finished = $g['finished'];
    $g['conversion']->delete();

    $this->actingAs($ctx['user'])
        ->from(route('org.products.edit-pricing', [$ctx['organization'], $finished]))
        ->post(route('org.products.components.store', [$ctx['organization'], $finished]), [
            'component_organization_product_id' => $material->id,
            'quantity' => '10',
            'usage_uom' => UnitOfMeasure::SquareFoot->value,
            'waste_basis_points' => 1000,
            'components_version' => $finished->components_version,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors();
});

test('phase 1c6b deactivate reactivate and manual pricing path without components', function () {
    $g = phase1c6bAcmGraph();
    $ctx = $g['ctx'];
    $finished = $g['finished'];
    $material = $g['material'];

    $this->actingAs($ctx['user'])
        ->post(route('org.products.components.store', [$ctx['organization'], $finished]), [
            'component_organization_product_id' => $material->id,
            'quantity' => '10',
            'usage_uom' => UnitOfMeasure::SquareFoot->value,
            'waste_basis_points' => 1000,
            'components_version' => 1,
        ])
        ->assertRedirect();

    $component = OrganizationProductComponent::query()->firstOrFail();
    $version = $finished->fresh()->components_version;

    $this->actingAs($ctx['user'])
        ->post(route('org.products.components.deactivate', [$ctx['organization'], $finished, $component]), [
            'components_version' => $version,
        ])
        ->assertRedirect();

    expect($component->fresh()->is_active)->toBeFalse()
        ->and($finished->fresh()->components_version)->toBe($version + 1);

    $this->actingAs($ctx['user'])
        ->post(route('org.products.components.reactivate', [$ctx['organization'], $finished, $component]), [
            'components_version' => $finished->fresh()->components_version,
        ])
        ->assertRedirect();

    expect($component->fresh()->is_active)->toBeTrue();

    $this->actingAs($ctx['user'])
        ->post(route('org.products.components.deactivate', [$ctx['organization'], $finished, $component]), [
            'components_version' => $finished->fresh()->components_version,
        ])
        ->assertRedirect();

    $manual = $finished->fresh();
    $this->actingAs($ctx['user'])
        ->patch(route('org.products.update-pricing', [$ctx['organization'], $manual]), [
            'pricing_version' => $manual->pricing_version,
            'components_version' => $manual->components_version,
            'material_cost' => '12.5000',
            'labor_cost' => '0',
            'overhead_mode' => OverheadMode::None->value,
            'pricing_method' => PricingMethod::Markup->value,
            'markup_percent' => '0',
        ])
        ->assertRedirect();

    expect($manual->fresh()->material_cost_micro_units)->toBe(Money::dollarsToMicroUnits('12.50'))
        ->and(AuditEvent::query()->where('action', 'catalog.organization_product.pricing_updated')->latest('id')->value('after_json'))
        ->toMatchArray(['material_source' => 'manual']);
});

test('phase 1c6b fixed price retains sell price while using component estimate', function () {
    $g = phase1c6bAcmGraph();
    $ctx = $g['ctx'];
    $finished = $g['finished'];
    $material = $g['material'];

    OrganizationProductComponent::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'organization_product_id' => $finished->id,
        'component_organization_product_id' => $material->id,
        'quantity_scaled' => ComponentCostEstimator::quantityToScaled('10'),
        'usage_uom' => UnitOfMeasure::SquareFoot,
        'waste_basis_points' => 1000,
        'is_active' => true,
    ]);
    $finished->update(['components_version' => 2]);

    $this->actingAs($ctx['user'])
        ->patch(route('org.products.update-pricing', [$ctx['organization'], $finished]), [
            'pricing_version' => 1,
            'components_version' => 2,
            'material_cost' => '1.0000',
            'labor_cost' => '0',
            'overhead_mode' => OverheadMode::None->value,
            'pricing_method' => PricingMethod::Fixed->value,
            'fixed_price' => '99.00',
        ])
        ->assertRedirect();

    $fresh = $finished->fresh();
    expect($fresh->material_cost_micro_units)->toBe(Money::dollarsToMicroUnits('27.50'))
        ->and($fresh->fixed_price_cents)->toBe(9900)
        ->and($fresh->pricing_method)->toBe(PricingMethod::Fixed);
});

test('phase 1c6b pricing save rolls back when estimation fails', function () {
    $g = phase1c6bAcmGraph();
    $ctx = $g['ctx'];
    $finished = $g['finished'];
    $material = $g['material'];

    OrganizationProductComponent::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'organization_product_id' => $finished->id,
        'component_organization_product_id' => $material->id,
        'quantity_scaled' => ComponentCostEstimator::quantityToScaled('10'),
        'usage_uom' => UnitOfMeasure::SquareFoot,
        'waste_basis_points' => 1000,
        'is_active' => true,
    ]);
    $finished->update(['components_version' => 2]);
    $g['conversion']->update(['is_active' => false]);

    $beforePricingVersion = $finished->pricing_version;
    $beforeMaterial = $finished->material_cost_micro_units;

    $this->actingAs($ctx['user'])
        ->patch(route('org.products.update-pricing', [$ctx['organization'], $finished]), [
            'pricing_version' => $beforePricingVersion,
            'components_version' => 2,
            'material_cost' => '1.0000',
            'labor_cost' => '0',
            'overhead_mode' => OverheadMode::None->value,
            'pricing_method' => PricingMethod::Markup->value,
            'markup_percent' => '0',
        ])
        ->assertSessionHasErrors();

    expect($finished->fresh()->pricing_version)->toBe($beforePricingVersion)
        ->and($finished->fresh()->material_cost_micro_units)->toBe($beforeMaterial);
});
