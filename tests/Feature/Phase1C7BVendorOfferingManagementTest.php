<?php

use App\Enums\ItemKind;
use App\Enums\UnitOfMeasure;
use App\Enums\VendorProductOfferingStatus;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\OrganizationProductSource;
use App\Models\OrganizationProductSourcePriceEvent;
use App\Models\ParentAccount;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorProductOffering;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Money;
use Illuminate\Support\Facades\Schema;

/**
 * @return array{
 *     user: User,
 *     parent: ParentAccount,
 *     organization: Organization,
 *     product: Product,
 *     organizationProduct: OrganizationProduct,
 *     vendor: Vendor
 * }
 */
function phase1c7bSeedGraph(string $orgRole = 'owner', ?string $parentRole = 'parent_owner'): array
{
    $ctx = createTenantUser($orgRole, $parentRole);
    $product = Product::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'item_kind' => ItemKind::Material,
        'sku' => 'MAT-ACM-'.uniqid(),
        'name' => 'ACM Sheet',
        'vendor_sku' => null,
        'unit_of_measure' => UnitOfMeasure::Sheet,
    ]);
    $organizationProduct = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'product_id' => $product->id,
        'is_purchasable' => true,
        'purchase_unit_of_measure' => UnitOfMeasure::Sheet,
        'purchase_cost_micro_units' => Money::dollarsToMicroUnits('80'),
        'pricing_version' => 3,
        'components_version' => 2,
    ]);
    $vendor = Vendor::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'name' => 'Grimco',
    ]);

    return [
        'user' => $ctx['user'],
        'parent' => $ctx['parent'],
        'organization' => $ctx['organization'],
        'membership' => $ctx['membership'],
        'product' => $product,
        'organizationProduct' => $organizationProduct,
        'vendor' => $vendor,
    ];
}

function phase1c7bOfferingPayload(array $overrides = []): array
{
    return [
        'vendor_sku' => 'GRIMCO-ACM-SHEET',
        'vendor_description' => '3mm ACM 48x96',
        'manufacturer' => '3A Composites',
        'manufacturer_part_number' => 'ACM-3MM',
        'product_url' => 'https://example.com/acm',
        'purchase_uom' => UnitOfMeasure::Sheet->value,
        'package_quantity' => '1',
        'minimum_order_quantity' => '5',
        'lead_time_days' => 3,
        ...$overrides,
    ];
}

test('phase 1c7b creates offering with audit and leaves legacy vendor id untouched', function () {
    $g = phase1c7bSeedGraph();

    $this->actingAs($g['user'])
        ->post(route('org.products.offerings.store', [
            'organization' => $g['organization'],
            'organizationProduct' => $g['organizationProduct'],
        ]), phase1c7bOfferingPayload([
            'product_id' => $g['product']->id,
            'vendor_id' => $g['vendor']->id,
            'package_quantity' => '10',
        ]))
        ->assertRedirect();

    $offering = VendorProductOffering::query()->firstOrFail();

    expect($offering->vendor_sku)->toBe('GRIMCO-ACM-SHEET')
        ->and($offering->package_quantity_scaled)->toBe(ComponentCostEstimator::quantityToScaled('10'))
        ->and($offering->status)->toBe(VendorProductOfferingStatus::Active)
        ->and(Schema::hasColumn('products', 'vendor_id'))->toBeFalse()
        ->and($g['product']->fresh()->sku)->toBe($g['product']->sku)
        ->and($g['organizationProduct']->fresh()->purchase_cost_micro_units)
        ->toBe(Money::dollarsToMicroUnits('80'))
        ->and($g['organizationProduct']->fresh()->pricing_version)->toBe(3)
        ->and($g['organizationProduct']->fresh()->components_version)->toBe(2)
        ->and(OrganizationProductSource::query()->count())->toBe(0)
        ->and(OrganizationProductSourcePriceEvent::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'catalog.vendor_product_offering.created')->count())->toBe(1);
});

test('phase 1c7b allows multiple offerings for same product vendor and rejects duplicate sku', function () {
    $g = phase1c7bSeedGraph();

    $this->actingAs($g['user'])
        ->post(route('org.products.offerings.store', [
            'organization' => $g['organization'],
            'organizationProduct' => $g['organizationProduct'],
        ]), phase1c7bOfferingPayload([
            'product_id' => $g['product']->id,
            'vendor_id' => $g['vendor']->id,
            'vendor_sku' => 'SKU-A',
        ]))
        ->assertRedirect();

    $this->actingAs($g['user'])
        ->post(route('org.products.offerings.store', [
            'organization' => $g['organization'],
            'organizationProduct' => $g['organizationProduct'],
        ]), phase1c7bOfferingPayload([
            'product_id' => $g['product']->id,
            'vendor_id' => $g['vendor']->id,
            'vendor_sku' => 'SKU-B',
            'package_quantity' => '10',
        ]))
        ->assertRedirect();

    expect(VendorProductOffering::query()->count())->toBe(2);

    $this->actingAs($g['user'])
        ->post(route('org.products.offerings.store', [
            'organization' => $g['organization'],
            'organizationProduct' => $g['organizationProduct'],
        ]), phase1c7bOfferingPayload([
            'product_id' => $g['product']->id,
            'vendor_id' => $g['vendor']->id,
            'vendor_sku' => 'SKU-A',
        ]))
        ->assertSessionHasErrors('vendor_sku');
});

test('phase 1c7b allows same vendor sku text at different vendors', function () {
    $g = phase1c7bSeedGraph();
    $otherVendor = Vendor::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'name' => 'Other Supply',
    ]);

    $this->actingAs($g['user'])
        ->post(route('org.products.offerings.store', [
            'organization' => $g['organization'],
            'organizationProduct' => $g['organizationProduct'],
        ]), phase1c7bOfferingPayload([
            'product_id' => $g['product']->id,
            'vendor_id' => $g['vendor']->id,
            'vendor_sku' => 'SAME-SKU',
        ]))
        ->assertRedirect();

    $this->actingAs($g['user'])
        ->post(route('org.products.offerings.store', [
            'organization' => $g['organization'],
            'organizationProduct' => $g['organizationProduct'],
        ]), phase1c7bOfferingPayload([
            'product_id' => $g['product']->id,
            'vendor_id' => $otherVendor->id,
            'vendor_sku' => 'SAME-SKU',
        ]))
        ->assertRedirect();

    expect(VendorProductOffering::query()->where('vendor_sku', 'SAME-SKU')->count())->toBe(2);
});

test('phase 1c7b validates package quantity moq lead time and url', function () {
    $g = phase1c7bSeedGraph();
    $route = [
        'organization' => $g['organization'],
        'organizationProduct' => $g['organizationProduct'],
    ];
    $base = [
        'product_id' => $g['product']->id,
        'vendor_id' => $g['vendor']->id,
    ];

    $this->actingAs($g['user'])
        ->post(route('org.products.offerings.store', $route), phase1c7bOfferingPayload([
            ...$base,
            'package_quantity' => '0',
        ]))
        ->assertSessionHasErrors('package_quantity');

    $this->actingAs($g['user'])
        ->post(route('org.products.offerings.store', $route), phase1c7bOfferingPayload([
            ...$base,
            'package_quantity' => '1.1234567',
        ]))
        ->assertSessionHasErrors('package_quantity');

    $this->actingAs($g['user'])
        ->post(route('org.products.offerings.store', $route), phase1c7bOfferingPayload([
            ...$base,
            'vendor_sku' => 'MOQ-BAD',
            'minimum_order_quantity' => '0',
        ]))
        ->assertSessionHasErrors('minimum_order_quantity');

    $this->actingAs($g['user'])
        ->post(route('org.products.offerings.store', $route), phase1c7bOfferingPayload([
            ...$base,
            'vendor_sku' => 'LEAD-BAD',
            'lead_time_days' => -1,
        ]))
        ->assertSessionHasErrors('lead_time_days');

    $this->actingAs($g['user'])
        ->post(route('org.products.offerings.store', $route), phase1c7bOfferingPayload([
            ...$base,
            'vendor_sku' => 'URL-BAD',
            'product_url' => 'ftp://example.com/file',
        ]))
        ->assertSessionHasErrors('product_url');
});

test('phase 1c7b discontinue and reactivate manage timestamps and audits', function () {
    $g = phase1c7bSeedGraph();
    $offering = VendorProductOffering::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'product_id' => $g['product']->id,
        'vendor_id' => $g['vendor']->id,
        'vendor_sku' => 'DISC-1',
    ]);

    $this->actingAs($g['user'])
        ->post(route('org.products.offerings.discontinue', [
            'organization' => $g['organization'],
            'organizationProduct' => $g['organizationProduct'],
            'vendorProductOffering' => $offering,
        ]))
        ->assertRedirect();

    $offering->refresh();
    expect($offering->status)->toBe(VendorProductOfferingStatus::Discontinued)
        ->and($offering->discontinued_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', 'catalog.vendor_product_offering.discontinued')->count())->toBe(1);

    $frozenDiscontinuedAt = $offering->discontinued_at?->toIso8601String();

    $this->actingAs($g['user'])
        ->patch(route('org.products.offerings.update', [
            'organization' => $g['organization'],
            'organizationProduct' => $g['organizationProduct'],
            'vendorProductOffering' => $offering,
        ]), phase1c7bOfferingPayload([
            'vendor_sku' => 'DISC-1-EDITED',
            'status' => 'active',
        ]))
        ->assertRedirect();

    $offering->refresh();
    expect($offering->vendor_sku)->toBe('DISC-1-EDITED')
        ->and($offering->status)->toBe(VendorProductOfferingStatus::Discontinued)
        ->and($offering->discontinued_at?->toIso8601String())->toBe($frozenDiscontinuedAt);

    $this->actingAs($g['user'])
        ->post(route('org.products.offerings.reactivate', [
            'organization' => $g['organization'],
            'organizationProduct' => $g['organizationProduct'],
            'vendorProductOffering' => $offering,
        ]))
        ->assertRedirect();

    $offering->refresh();
    expect($offering->status)->toBe(VendorProductOfferingStatus::Active)
        ->and($offering->discontinued_at)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'catalog.vendor_product_offering.reactivated')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'catalog.vendor_product_offering.updated')->count())->toBe(1);
});

test('phase 1c7b parent authorization required and org-only admin forbidden', function () {
    $parentCtx = phase1c7bSeedGraph('owner', 'parent_owner');
    $orgOnly = createTenantUser('owner', null);

    $product = Product::factory()->create([
        'parent_account_id' => $orgOnly['parent']->id,
    ]);
    $organizationProduct = OrganizationProduct::factory()->create([
        'parent_account_id' => $orgOnly['parent']->id,
        'organization_id' => $orgOnly['organization']->id,
        'product_id' => $product->id,
    ]);
    $vendor = Vendor::factory()->create([
        'parent_account_id' => $orgOnly['parent']->id,
    ]);

    $this->actingAs($orgOnly['user'])
        ->post(route('org.products.offerings.store', [
            'organization' => $orgOnly['organization'],
            'organizationProduct' => $organizationProduct,
        ]), phase1c7bOfferingPayload([
            'product_id' => $product->id,
            'vendor_id' => $vendor->id,
        ]))
        ->assertForbidden();

    expect(VendorProductOffering::query()->count())->toBe(0);

    $this->actingAs($parentCtx['user'])
        ->get(route('org.products.offerings.create', [
            'organization' => $parentCtx['organization'],
            'organizationProduct' => $parentCtx['organizationProduct'],
        ]))
        ->assertOk();
});

test('phase 1c7b wrong parent binding returns 404 and ignores request tenant ids', function () {
    $g = phase1c7bSeedGraph();
    $other = phase1c7bSeedGraph();

    $foreignOffering = VendorProductOffering::factory()->create([
        'parent_account_id' => $other['parent']->id,
        'product_id' => $other['product']->id,
        'vendor_id' => $other['vendor']->id,
        'vendor_sku' => 'FOREIGN',
    ]);

    $this->actingAs($g['user'])
        ->get(route('org.products.offerings.show', [
            'organization' => $g['organization'],
            'organizationProduct' => $g['organizationProduct'],
            'vendorProductOffering' => $foreignOffering,
        ]))
        ->assertNotFound();

    $this->actingAs($g['user'])
        ->post(route('org.products.offerings.store', [
            'organization' => $g['organization'],
            'organizationProduct' => $g['organizationProduct'],
        ]), phase1c7bOfferingPayload([
            'product_id' => $g['product']->id,
            'vendor_id' => $g['vendor']->id,
            'vendor_sku' => 'TENANT-STRIP',
            'parent_account_id' => $other['parent']->id,
            'organization_id' => $other['organization']->id,
        ]))
        ->assertRedirect();

    $offering = VendorProductOffering::query()->where('vendor_sku', 'TENANT-STRIP')->firstOrFail();
    expect($offering->parent_account_id)->toBe($g['parent']->id);
});

test('phase 1c7b product and vendor show list offerings without cost fields', function () {
    $g = phase1c7bSeedGraph();
    VendorProductOffering::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'product_id' => $g['product']->id,
        'vendor_id' => $g['vendor']->id,
        'vendor_sku' => 'LIST-1',
        'package_quantity_scaled' => ComponentCostEstimator::quantityToScaled('10'),
    ]);

    $productShow = $this->actingAs($g['user'])
        ->get(route('org.products.show', [
            'organization' => $g['organization'],
            'organizationProduct' => $g['organizationProduct'],
        ]))
        ->assertOk();

    $productShow->assertInertia(fn ($page) => $page
        ->component('products/Show')
        ->has('vendorOfferings', 1)
        ->where('vendorOfferings.0.vendor_sku', 'LIST-1')
        ->missing('vendorOfferings.0.current_package_price_micro_units')
        ->missing('vendorOfferings.0.price')
        ->where('canManageOfferings', true));

    $vendorShow = $this->actingAs($g['user'])
        ->get(route('org.vendors.show', [
            'organization' => $g['organization'],
            'vendor' => $g['vendor'],
        ]))
        ->assertOk();

    $vendorShow->assertInertia(fn ($page) => $page
        ->component('vendors/Show')
        ->has('vendorOfferings', 1)
        ->where('vendorOfferings.0.vendor_sku', 'LIST-1')
        ->missing('vendorOfferings.0.price'));
});

test('phase 1c7b legacy mutations remain 409', function () {
    $g = phase1c7bSeedGraph();

    $this->actingAs($g['user'])
        ->postJson(route('products.store'), [
            'name' => 'Legacy',
            'sku' => 'LEGACY-OFFER',
        ])
        ->assertStatus(409);

    $this->actingAs($g['user'])
        ->postJson(route('vendors.store'), [
            'name' => 'Legacy Vendor',
        ])
        ->assertStatus(409);
});

test('phase 1c7b vendor create path works and normalizes sku whitespace', function () {
    $g = phase1c7bSeedGraph();

    $this->actingAs($g['user'])
        ->post(route('org.vendors.offerings.store', [
            'organization' => $g['organization'],
            'vendor' => $g['vendor'],
        ]), phase1c7bOfferingPayload([
            'product_id' => $g['product']->id,
            'vendor_id' => $g['vendor']->id,
            'vendor_sku' => '  TRIMMED-SKU  ',
            'package_quantity' => '1.5',
        ]))
        ->assertRedirect();

    $offering = VendorProductOffering::query()->firstOrFail();
    expect($offering->vendor_sku)->toBe('TRIMMED-SKU')
        ->and($offering->package_quantity_scaled)->toBe(ComponentCostEstimator::quantityToScaled('1.5'));
});
