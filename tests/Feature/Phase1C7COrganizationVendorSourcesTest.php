<?php

use App\Enums\ItemKind;
use App\Enums\OverheadMode;
use App\Enums\PricingMethod;
use App\Enums\UnitOfMeasure;
use App\Enums\VendorProductOfferingStatus;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\OrganizationProductComponent;
use App\Models\OrganizationProductSource;
use App\Models\OrganizationProductSourcePriceEvent;
use App\Models\OrganizationProductUnitConversion;
use App\Models\ParentAccount;
use App\Models\ParentAccountMembership;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorProductOffering;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Money;
use App\Support\Tenancy\RoleAssigner;

/**
 * @return array{
 *     user: User,
 *     parent: ParentAccount,
 *     organization: Organization,
 *     materialMaster: Product,
 *     material: OrganizationProduct,
 *     offering: VendorProductOffering,
 *     vendor: Vendor
 * }
 */
function phase1c7cSeedGraph(string $orgRole = 'owner', ?string $parentRole = 'parent_owner'): array
{
    $ctx = createTenantUser($orgRole, $parentRole);

    $materialMaster = Product::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'item_kind' => ItemKind::Material,
        'name' => 'ACM Sheet',
        'sku' => 'MAT-ACM-'.uniqid(),
        'vendor_id' => null,
        'unit_of_measure' => UnitOfMeasure::Sheet,
    ]);

    $material = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'product_id' => $materialMaster->id,
        'is_purchasable' => true,
        'is_available' => true,
        'is_sellable' => false,
        'purchase_unit_of_measure' => UnitOfMeasure::Sheet,
        'purchase_cost_micro_units' => null,
        'pricing_version' => 1,
        'components_version' => 1,
        'currency_code' => 'USD',
    ]);

    $vendor = Vendor::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'name' => 'Grimco',
    ]);

    $offering = VendorProductOffering::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'product_id' => $materialMaster->id,
        'vendor_id' => $vendor->id,
        'vendor_sku' => 'GRIMCO-ACM-10',
        'purchase_uom' => UnitOfMeasure::Sheet,
        'package_quantity_scaled' => ComponentCostEstimator::quantityToScaled('10'),
        'status' => VendorProductOfferingStatus::Active,
    ]);

    return [
        'user' => $ctx['user'],
        'parent' => $ctx['parent'],
        'organization' => $ctx['organization'],
        'membership' => $ctx['membership'],
        'materialMaster' => $materialMaster,
        'material' => $material,
        'offering' => $offering,
        'vendor' => $vendor,
    ];
}

test('phase 1c7c attaches source and rejects duplicates and mismatched offerings', function () {
    $g = phase1c7cSeedGraph();

    $this->actingAs($g['user'])
        ->post(route('org.products.sources.store', [$g['organization'], $g['material']]), [
            'vendor_product_offering_id' => $g['offering']->id,
            'package_price' => '800.0000',
        ])
        ->assertRedirect();

    $source = OrganizationProductSource::query()->firstOrFail();
    expect($source->current_package_price_micro_units)->toBe(Money::dollarsToMicroUnits('800'))
        ->and($source->price_version)->toBe(1)
        ->and(OrganizationProductSourcePriceEvent::query()->count())->toBe(1)
        ->and(OrganizationProductSourcePriceEvent::query()->value('effective_purchase_unit_cost_micro_units'))
        ->toBe(Money::dollarsToMicroUnits('80'))
        ->and(AuditEvent::query()->where('action', 'catalog.organization_product_source.attached')->count())->toBe(1);

    $this->actingAs($g['user'])
        ->post(route('org.products.sources.store', [$g['organization'], $g['material']]), [
            'vendor_product_offering_id' => $g['offering']->id,
            'package_price' => '800.0000',
        ])
        ->assertSessionHasErrors('vendor_product_offering_id');

    $otherMaster = Product::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'item_kind' => ItemKind::Material,
    ]);
    $mismatched = VendorProductOffering::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'product_id' => $otherMaster->id,
        'vendor_id' => $g['vendor']->id,
        'vendor_sku' => 'OTHER',
    ]);

    $this->actingAs($g['user'])
        ->post(route('org.products.sources.store', [$g['organization'], $g['material']]), [
            'vendor_product_offering_id' => $mismatched->id,
        ])
        ->assertSessionHasErrors('vendor_product_offering_id');
});

test('phase 1c7c pelican and brim can price the same offering differently', function () {
    $pelican = phase1c7cSeedGraph();
    $brimOrg = Organization::factory()->create(['parent_account_id' => $pelican['parent']->id]);
    $brimUser = User::factory()->create();
    $brimMembership = Membership::factory()->create([
        'organization_id' => $brimOrg->id,
        'user_id' => $brimUser->id,
    ]);
    $owner = Role::query()->where('key', 'owner')->firstOrFail();
    app(RoleAssigner::class)->assignToOrganizationMembership($brimMembership, $owner);
    $parentMembership = ParentAccountMembership::factory()->create([
        'parent_account_id' => $pelican['parent']->id,
        'user_id' => $brimUser->id,
    ]);
    $parentOwner = Role::query()->where('key', 'parent_owner')->firstOrFail();
    app(RoleAssigner::class)->assignToParentMembership($parentMembership, $parentOwner);

    $brimMaterial = OrganizationProduct::factory()->create([
        'parent_account_id' => $pelican['parent']->id,
        'organization_id' => $brimOrg->id,
        'product_id' => $pelican['materialMaster']->id,
        'is_purchasable' => true,
        'purchase_unit_of_measure' => UnitOfMeasure::Sheet,
        'currency_code' => 'USD',
    ]);

    $this->actingAs($pelican['user'])
        ->post(route('org.products.sources.store', [$pelican['organization'], $pelican['material']]), [
            'vendor_product_offering_id' => $pelican['offering']->id,
            'package_price' => '800.0000',
        ])
        ->assertRedirect();

    $this->actingAs($brimUser)
        ->post(route('org.products.sources.store', [$brimOrg, $brimMaterial]), [
            'vendor_product_offering_id' => $pelican['offering']->id,
            'package_price' => '780.0000',
        ])
        ->assertRedirect();

    $pelicanSource = OrganizationProductSource::query()
        ->where('organization_id', $pelican['organization']->id)
        ->firstOrFail();
    $brimSource = OrganizationProductSource::query()
        ->where('organization_id', $brimOrg->id)
        ->firstOrFail();

    expect($pelicanSource->current_package_price_micro_units)->toBe(Money::dollarsToMicroUnits('800'))
        ->and($brimSource->current_package_price_micro_units)->toBe(Money::dollarsToMicroUnits('780'))
        ->and(OrganizationProductSource::query()->count())->toBe(2);

    $this->actingAs($pelican['user'])
        ->post(route('org.products.sources.prefer', [
            $pelican['organization'],
            $pelican['material'],
            $pelicanSource,
        ]))
        ->assertRedirect();

    $this->actingAs($brimUser)
        ->post(route('org.products.sources.prefer', [$brimOrg, $brimMaterial, $brimSource]))
        ->assertRedirect();

    expect($pelican['material']->fresh()->purchase_cost_micro_units)->toBe(Money::dollarsToMicroUnits('80'))
        ->and($brimMaterial->fresh()->purchase_cost_micro_units)->toBe(Money::dollarsToMicroUnits('78'));
});

test('phase 1c7c preferred selection clearing blocking and invalidation', function () {
    $g = phase1c7cSeedGraph();

    $finishedMaster = Product::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'item_kind' => ItemKind::Product,
        'name' => 'ACM Sign',
    ]);
    $finished = OrganizationProduct::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'product_id' => $finishedMaster->id,
        'is_sellable' => true,
        'material_cost_micro_units' => Money::dollarsToMicroUnits('1'),
        'labor_cost_micro_units' => 0,
        'overhead_mode' => OverheadMode::None,
        'pricing_method' => PricingMethod::Markup,
        'markup_basis_points' => 0,
        'pricing_version' => 1,
        'components_version' => 1,
    ]);
    OrganizationProductUnitConversion::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'organization_product_id' => $g['material']->id,
        'from_unit' => UnitOfMeasure::Sheet,
        'to_unit' => UnitOfMeasure::SquareFoot,
        'numerator' => 32,
        'denominator' => 1,
        'is_active' => true,
    ]);
    OrganizationProductComponent::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'organization_product_id' => $finished->id,
        'component_organization_product_id' => $g['material']->id,
        'quantity_scaled' => ComponentCostEstimator::quantityToScaled('11'),
        'usage_uom' => UnitOfMeasure::SquareFoot,
        'waste_basis_points' => 0,
        'is_active' => true,
    ]);

    $this->actingAs($g['user'])
        ->post(route('org.products.sources.store', [$g['organization'], $g['material']]), [
            'vendor_product_offering_id' => $g['offering']->id,
            'package_price' => '800.0000',
        ])
        ->assertRedirect();

    $source = OrganizationProductSource::query()->firstOrFail();

    $this->actingAs($g['user'])
        ->post(route('org.products.sources.prefer', [$g['organization'], $g['material'], $source]))
        ->assertRedirect();

    expect($g['material']->fresh()->preferred_source_id)->toBe($source->id)
        ->and($g['material']->fresh()->purchase_cost_micro_units)->toBe(Money::dollarsToMicroUnits('80'))
        ->and($finished->fresh()->components_version)->toBe(2)
        ->and($finished->fresh()->pricing_version)->toBe(1)
        ->and($g['material']->fresh()->pricing_version)->toBe(1);

    $this->actingAs($g['user'])
        ->patch(route('org.products.update-purchase-cost', [$g['organization'], $g['material']]), [
            'purchase_cost' => '70.0000',
        ])
        ->assertSessionHasErrors('purchase_cost');

    $this->actingAs($g['user'])
        ->patch(route('org.products.sources.update-price', [$g['organization'], $g['material'], $source]), [
            'package_price' => '800.0000',
            'expected_price_version' => 1,
        ])
        ->assertRedirect();

    expect($source->fresh()->price_version)->toBe(1)
        ->and(OrganizationProductSourcePriceEvent::query()->count())->toBe(1);

    $this->actingAs($g['user'])
        ->patch(route('org.products.sources.update-price', [$g['organization'], $g['material'], $source]), [
            'package_price' => '900.0000',
            'expected_price_version' => 1,
        ])
        ->assertRedirect();

    expect($source->fresh()->price_version)->toBe(2)
        ->and($g['material']->fresh()->purchase_cost_micro_units)->toBe(Money::dollarsToMicroUnits('90'))
        ->and($finished->fresh()->components_version)->toBe(3)
        ->and($finished->fresh()->material_cost_micro_units)->toBe(Money::dollarsToMicroUnits('1'))
        ->and(OrganizationProductSourcePriceEvent::query()->count())->toBe(2);

    $this->actingAs($g['user'])
        ->post(route('org.products.sources.deactivate', [$g['organization'], $g['material'], $source]))
        ->assertSessionHasErrors('is_active');

    $this->actingAs($g['user'])
        ->post(route('org.products.sources.clear-preferred', [$g['organization'], $g['material']]))
        ->assertRedirect();

    expect($g['material']->fresh()->preferred_source_id)->toBeNull()
        ->and($g['material']->fresh()->purchase_cost_micro_units)->toBe(Money::dollarsToMicroUnits('90'))
        ->and($finished->fresh()->components_version)->toBe(3);

    $this->actingAs($g['user'])
        ->patch(route('org.products.update-purchase-cost', [$g['organization'], $g['material']]), [
            'purchase_cost' => '88.0000',
        ])
        ->assertRedirect();
});

test('phase 1c7c nonpreferred price does not change op cost and stale versions 409', function () {
    $g = phase1c7cSeedGraph();

    $second = VendorProductOffering::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'product_id' => $g['materialMaster']->id,
        'vendor_id' => $g['vendor']->id,
        'vendor_sku' => 'GRIMCO-ACM-ALT',
        'purchase_uom' => UnitOfMeasure::Sheet,
        'package_quantity_scaled' => ComponentCostEstimator::quantityToScaled('1'),
    ]);

    $this->actingAs($g['user'])
        ->post(route('org.products.sources.store', [$g['organization'], $g['material']]), [
            'vendor_product_offering_id' => $g['offering']->id,
            'package_price' => '800.0000',
        ])
        ->assertRedirect();
    $this->actingAs($g['user'])
        ->post(route('org.products.sources.store', [$g['organization'], $g['material']]), [
            'vendor_product_offering_id' => $second->id,
            'package_price' => '75.0000',
        ])
        ->assertRedirect();

    $preferred = OrganizationProductSource::query()
        ->where('vendor_product_offering_id', $g['offering']->id)
        ->firstOrFail();
    $other = OrganizationProductSource::query()
        ->where('vendor_product_offering_id', $second->id)
        ->firstOrFail();

    $this->actingAs($g['user'])
        ->post(route('org.products.sources.prefer', [$g['organization'], $g['material'], $preferred]))
        ->assertRedirect();

    $this->actingAs($g['user'])
        ->patch(route('org.products.sources.update-price', [$g['organization'], $g['material'], $other]), [
            'package_price' => '70.0000',
            'expected_price_version' => 1,
        ])
        ->assertRedirect();

    expect($g['material']->fresh()->purchase_cost_micro_units)->toBe(Money::dollarsToMicroUnits('80'))
        ->and($other->fresh()->price_version)->toBe(2);

    $this->actingAs($g['user'])
        ->patch(route('org.products.sources.update-price', [$g['organization'], $g['material'], $preferred]), [
            'package_price' => '850.0000',
            'expected_price_version' => 99,
        ])
        ->assertStatus(409);

    $this->actingAs($g['user'])
        ->post(route('org.products.sources.prefer', [$g['organization'], $g['material'], $other]), [
            'expected_preferred_source_id' => 999999,
        ])
        ->assertStatus(409);
});

test('phase 1c7c offering structural and discontinue guards with descriptive edits allowed', function () {
    $g = phase1c7cSeedGraph();

    $this->actingAs($g['user'])
        ->post(route('org.products.sources.store', [$g['organization'], $g['material']]), [
            'vendor_product_offering_id' => $g['offering']->id,
            'package_price' => '800.0000',
        ])
        ->assertRedirect();
    $source = OrganizationProductSource::query()->firstOrFail();
    $this->actingAs($g['user'])
        ->post(route('org.products.sources.prefer', [$g['organization'], $g['material'], $source]))
        ->assertRedirect();

    $this->actingAs($g['user'])
        ->post(route('org.products.offerings.discontinue', [
            $g['organization'],
            $g['material'],
            $g['offering'],
        ]))
        ->assertSessionHasErrors('status');

    $this->actingAs($g['user'])
        ->patch(route('org.products.offerings.update', [
            $g['organization'],
            $g['material'],
            $g['offering'],
        ]), [
            'vendor_sku' => $g['offering']->vendor_sku,
            'vendor_description' => 'Updated description only',
            'manufacturer' => '3A',
            'manufacturer_part_number' => 'ACM',
            'product_url' => null,
            'purchase_uom' => UnitOfMeasure::Sheet->value,
            'package_quantity' => '10',
            'minimum_order_quantity' => null,
            'lead_time_days' => null,
        ])
        ->assertRedirect();

    expect($g['offering']->fresh()->vendor_description)->toBe('Updated description only');

    $this->actingAs($g['user'])
        ->patch(route('org.products.offerings.update', [
            $g['organization'],
            $g['material'],
            $g['offering'],
        ]), [
            'vendor_sku' => $g['offering']->vendor_sku,
            'vendor_description' => 'Updated description only',
            'manufacturer' => '3A',
            'manufacturer_part_number' => 'ACM',
            'product_url' => null,
            'purchase_uom' => UnitOfMeasure::Sheet->value,
            'package_quantity' => '5',
            'minimum_order_quantity' => null,
            'lead_time_days' => null,
        ])
        ->assertSessionHasErrors('package_quantity');
});

test('phase 1c7c authorization cost redaction cross-org 404 and legacy 409', function () {
    $g = phase1c7cSeedGraph();

    $this->actingAs($g['user'])
        ->post(route('org.products.sources.store', [$g['organization'], $g['material']]), [
            'vendor_product_offering_id' => $g['offering']->id,
            'package_price' => '800.0000',
        ])
        ->assertRedirect();
    $source = OrganizationProductSource::query()->firstOrFail();

    $sales = createTenantUser('salesperson');
    $this->actingAs($sales['user'])
        ->get(route('org.products.sources.show', [
            $g['organization'],
            $g['material'],
            $source,
        ]))
        ->assertNotFound();

    $foreign = phase1c7cSeedGraph();
    $foreignSource = OrganizationProductSource::factory()->create([
        'parent_account_id' => $foreign['parent']->id,
        'organization_id' => $foreign['organization']->id,
        'organization_product_id' => $foreign['material']->id,
        'vendor_product_offering_id' => $foreign['offering']->id,
    ]);

    $this->actingAs($g['user'])
        ->get(route('org.products.sources.show', [
            $g['organization'],
            $g['material'],
            $foreignSource,
        ]))
        ->assertNotFound();

    $this->actingAs($g['user'])
        ->get(route('org.products.show', [$g['organization'], $g['material']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('vendorSources', 1)
            ->where('vendorSources.0.current_package_price', '800.0000'));

    $salesSame = User::factory()->create();
    $membership = Membership::factory()->create([
        'organization_id' => $g['organization']->id,
        'user_id' => $salesSame->id,
    ]);
    $role = Role::query()->where('key', 'salesperson')->firstOrFail();
    app(RoleAssigner::class)->assignToOrganizationMembership($membership, $role);

    $this->actingAs($salesSame)
        ->get(route('org.products.show', [$g['organization'], $g['material']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('vendorSources', 1)
            ->missing('vendorSources.0.current_package_price')
            ->missing('vendorSources.0.effective_purchase_unit_cost'));

    $this->actingAs($g['user'])
        ->post('/products', ['name' => 'legacy'])
        ->assertStatus(409);

    expect(OrganizationProductSource::query()->count())->toBe(2)
        ->and(OrganizationProductSourcePriceEvent::query()->count())->toBe(1)
        ->and($g['materialMaster']->fresh()->vendor_id)->toBeNull();
});
