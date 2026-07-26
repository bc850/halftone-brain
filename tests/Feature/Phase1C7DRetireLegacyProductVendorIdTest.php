<?php

use App\Enums\ItemKind;
use App\Enums\OverheadMode;
use App\Enums\PricingMethod;
use App\Enums\ProductFamily;
use App\Enums\UnitOfMeasure;
use App\Enums\VendorProductOfferingStatus;
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const PHASE_1C7D_DROP_LEGACY_VENDOR_ID = '2026_07_26_200508_drop_legacy_products_vendor_id_column';

function phase1c7dHasForeign(string $table, string $name, array $columns, string $foreignTable): bool
{
    $driver = Schema::getConnection()->getDriverName();

    foreach (Schema::getForeignKeys($table) as $foreign) {
        if (($foreign['columns'] ?? []) !== $columns || ($foreign['foreign_table'] ?? null) !== $foreignTable) {
            continue;
        }

        if ($driver === 'mysql') {
            return ($foreign['name'] ?? null) === $name;
        }

        return true;
    }

    return false;
}

function phase1c7dHasIndex(string $table, string $indexName): bool
{
    foreach (Schema::getIndexes($table) as $index) {
        if (($index['name'] ?? null) === $indexName) {
            return true;
        }
    }

    return false;
}

function phase1c7dForeignOnDelete(string $table, array $columns, string $foreignTable): ?string
{
    foreach (Schema::getForeignKeys($table) as $foreign) {
        if (($foreign['columns'] ?? []) !== $columns || ($foreign['foreign_table'] ?? null) !== $foreignTable) {
            continue;
        }

        return strtolower((string) ($foreign['on_delete'] ?? ''));
    }

    return null;
}

function phase1c7dRollbackDrop(): void
{
    Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
}

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
function phase1c7dSeedGraph(): array
{
    $ctx = createTenantUser('owner', 'parent_owner');
    $product = Product::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'item_kind' => ItemKind::Material,
        'sku' => 'MAT-1C7D-'.uniqid(),
        'name' => 'Legacy Retire Material',
        'vendor_sku' => null,
        'unit_of_measure' => UnitOfMeasure::Sheet,
    ]);
    $organizationProduct = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'product_id' => $product->id,
        'is_purchasable' => true,
        'purchase_unit_of_measure' => UnitOfMeasure::Sheet,
        'purchase_cost_micro_units' => Money::dollarsToMicroUnits('25.00'),
        'pricing_version' => 2,
        'components_version' => 1,
    ]);
    $vendor = Vendor::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'name' => '1C7D Vendor',
    ]);

    return [
        'user' => $ctx['user'],
        'parent' => $ctx['parent'],
        'organization' => $ctx['organization'],
        'product' => $product,
        'organizationProduct' => $organizationProduct,
        'vendor' => $vendor,
    ];
}

test('fully migrated schema lacks products.vendor_id and product has no vendor relation', function () {
    expect(Schema::hasColumn('products', 'vendor_id'))->toBeFalse()
        ->and(Schema::hasColumn('products', 'vendor_sku'))->toBeTrue()
        ->and(Schema::hasTable('vendor_product_offerings'))->toBeTrue()
        ->and(Schema::hasTable('organization_product_sources'))->toBeTrue()
        ->and(method_exists(Product::class, 'vendor'))->toBeFalse()
        ->and(method_exists(Product::class, 'vendorProductOfferings'))->toBeTrue()
        ->and(method_exists(Vendor::class, 'products'))->toBeFalse()
        ->and(method_exists(Vendor::class, 'vendorProductOfferings'))->toBeTrue();
});

test('product create and master update strip legacy vendor_id without writing offerings', function () {
    $g = phase1c7dSeedGraph();

    $this->actingAs($g['user'])
        ->post(route('org.products.store', $g['organization']), [
            'name' => 'No Legacy Vendor Product',
            'sku' => 'NO-LEGACY-'.uniqid(),
            'product_family' => ProductFamily::Signage->value,
            'item_kind' => ItemKind::Product->value,
            'vendor_sku' => 'TEXT-SKU-ONLY',
            'vendor_id' => $g['vendor']->id,
            'unit_of_measure' => UnitOfMeasure::Each->value,
            'is_active' => true,
            'is_available' => true,
            'is_sellable' => true,
            'is_purchasable' => false,
            'inventory_tracking_mode' => 'none',
            'material_cost' => '10',
            'labor_cost' => '5',
            'overhead_mode' => OverheadMode::None->value,
            'pricing_method' => PricingMethod::Markup->value,
            'markup_percent' => '25',
        ])
        ->assertRedirect();

    $created = Product::query()->where('name', 'No Legacy Vendor Product')->firstOrFail();

    expect(Schema::hasColumn('products', 'vendor_id'))->toBeFalse()
        ->and($created->vendor_sku)->toBe('TEXT-SKU-ONLY')
        ->and(VendorProductOffering::query()->where('product_id', $created->id)->count())->toBe(0)
        ->and(OrganizationProductSource::query()->count())->toBe(0);

    $this->actingAs($g['user'])
        ->patch(route('org.products.update-master', [
            'organization' => $g['organization'],
            'organizationProduct' => $g['organizationProduct'],
        ]), [
            'name' => $g['product']->name,
            'sku' => $g['product']->sku,
            'product_family' => $g['product']->product_family->value,
            'item_kind' => $g['product']->item_kind->value,
            'vendor_sku' => 'UPDATED-SKU',
            'vendor_id' => $g['vendor']->id,
            'unit_of_measure' => $g['product']->unit_of_measure->value,
            'is_active' => true,
        ])
        ->assertRedirect();

    expect($g['product']->fresh()->vendor_sku)->toBe('UPDATED-SKU')
        ->and(VendorProductOffering::query()->count())->toBe(0)
        ->and(OrganizationProductSource::query()->count())->toBe(0);
});

test('product resources and forms omit legacy vendor fields while offerings and vendors work', function () {
    $g = phase1c7dSeedGraph();
    $offering = VendorProductOffering::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'product_id' => $g['product']->id,
        'vendor_id' => $g['vendor']->id,
        'vendor_sku' => 'OFFER-1C7D',
        'purchase_uom' => UnitOfMeasure::Sheet,
        'package_quantity_scaled' => ComponentCostEstimator::QUANTITY_SCALE_FACTOR,
        'status' => VendorProductOfferingStatus::Active,
    ]);

    $this->actingAs($g['user'])
        ->get(route('org.products.show', [$g['organization'], $g['organizationProduct']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('products/Show')
            ->missing('product.product.vendor')
            ->where('vendorOfferings.0.vendor_sku', 'OFFER-1C7D')
            ->where('vendorOfferings.0.vendor.id', $g['vendor']->id));

    $this->actingAs($g['user'])
        ->get(route('org.products.create', $g['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('products/Create')
            ->missing('vendors'));

    $this->actingAs($g['user'])
        ->get(route('org.products.edit-master', [$g['organization'], $g['organizationProduct']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('products/EditMaster')
            ->missing('vendors')
            ->missing('product.product.vendor'));

    $this->actingAs($g['user'])
        ->get(route('org.vendors.index', $g['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('vendors/Index')
            ->has('vendors.data.0.offerings_count')
            ->missing('vendors.data.0.products_count'));

    $this->actingAs($g['user'])
        ->get(route('org.vendors.show', [$g['organization'], $g['vendor']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('vendors/Show')
            ->missing('vendor.products')
            ->where('vendorOfferings.0.id', $offering->id));
});

test('offering and source workflows still operate without products.vendor_id', function () {
    $g = phase1c7dSeedGraph();

    $this->actingAs($g['user'])
        ->post(route('org.products.offerings.store', [
            'organization' => $g['organization'],
            'organizationProduct' => $g['organizationProduct'],
        ]), [
            'product_id' => $g['product']->id,
            'vendor_id' => $g['vendor']->id,
            'vendor_sku' => 'WORKFLOW-SKU',
            'purchase_uom' => UnitOfMeasure::Sheet->value,
            'package_quantity' => '1',
        ])
        ->assertRedirect();

    $offering = VendorProductOffering::query()->firstOrFail();

    $this->actingAs($g['user'])
        ->post(route('org.products.sources.store', [
            'organization' => $g['organization'],
            'organizationProduct' => $g['organizationProduct'],
        ]), [
            'vendor_product_offering_id' => $offering->id,
            'package_price' => '100.0000',
            'is_active' => true,
        ])
        ->assertRedirect();

    expect(OrganizationProductSource::query()->count())->toBe(1)
        ->and($g['organizationProduct']->fresh()->purchase_cost_micro_units)
        ->toBe(Money::dollarsToMicroUnits('25.00'))
        ->and(Schema::hasColumn('products', 'vendor_id'))->toBeFalse();
});

test('migrate pretend for pending legacy vendor_id drop succeeds without schema change', function () {
    phase1c7dRollbackDrop();

    expect(Schema::hasColumn('products', 'vendor_id'))->toBeTrue()
        ->and(DB::table('migrations')->where('migration', PHASE_1C7D_DROP_LEGACY_VENDOR_ID)->exists())->toBeFalse();

    $exit = Artisan::call('migrate', ['--pretend' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain(PHASE_1C7D_DROP_LEGACY_VENDOR_ID)
        ->and($output)->toMatch('/drop|vendor_id/i')
        ->and(Schema::hasColumn('products', 'vendor_id'))->toBeTrue()
        ->and(DB::table('migrations')->where('migration', PHASE_1C7D_DROP_LEGACY_VENDOR_ID)->exists())->toBeFalse()
        ->and(Schema::hasTable('vendor_product_offerings'))->toBeTrue()
        ->and(Schema::hasTable('organization_product_sources'))->toBeTrue();

    Artisan::call('migrate', ['--force' => true]);
    expect(Schema::hasColumn('products', 'vendor_id'))->toBeFalse();
});

test('clean migration drop succeeds and remigration drops again after rollback', function () {
    expect(Schema::hasColumn('products', 'vendor_id'))->toBeFalse();

    $offeringCount = VendorProductOffering::query()->count();
    $sourceCount = OrganizationProductSource::query()->count();
    $eventCount = OrganizationProductSourcePriceEvent::query()->count();

    // Avoid child rows on SQLite: RefreshDatabase transactions make PRAGMA foreign_keys
    // ineffective, so rebuilding products during rollback/remigrate would fail.
    $seedProduct = null;
    $purchaseCost = null;
    if (Schema::getConnection()->getDriverName() !== 'sqlite') {
        $g = phase1c7dSeedGraph();
        $seedProduct = $g['product'];
        $purchaseCost = $g['organizationProduct']->purchase_cost_micro_units;
    }

    phase1c7dRollbackDrop();

    expect(Schema::hasColumn('products', 'vendor_id'))->toBeTrue()
        ->and(phase1c7dHasIndex('products', 'products_vendor_id_index'))->toBeTrue()
        ->and(phase1c7dHasIndex('products', 'products_parent_account_id_vendor_id_index'))->toBeTrue();

    if (Schema::getConnection()->getDriverName() === 'mysql') {
        expect(phase1c7dHasForeign('products', 'products_vendor_id_foreign', ['vendor_id'], 'vendors'))->toBeTrue()
            ->and(phase1c7dForeignOnDelete('products', ['vendor_id'], 'vendors'))->toBe('set null')
            ->and(phase1c7dHasForeign('products', 'pr_pa_ve_fk', ['parent_account_id', 'vendor_id'], 'vendors'))->toBeTrue()
            ->and(phase1c7dForeignOnDelete('products', ['parent_account_id', 'vendor_id'], 'vendors'))->toBe('restrict');
    }

    expect(Schema::hasTable('vendor_product_offerings'))->toBeTrue()
        ->and(Schema::hasTable('organization_product_sources'))->toBeTrue()
        ->and(VendorProductOffering::query()->count())->toBe($offeringCount)
        ->and(OrganizationProductSource::query()->count())->toBe($sourceCount)
        ->and(OrganizationProductSourcePriceEvent::query()->count())->toBe($eventCount);

    if ($seedProduct !== null) {
        expect(DB::table('products')->where('id', $seedProduct->id)->value('vendor_id'))->toBeNull()
            ->and($seedProduct->organizationProducts()->first()->purchase_cost_micro_units)->toBe($purchaseCost);
    }

    Artisan::call('migrate', ['--force' => true]);

    expect(Schema::hasColumn('products', 'vendor_id'))->toBeFalse()
        ->and(phase1c7dHasForeign('products', 'products_vendor_id_foreign', ['vendor_id'], 'vendors'))->toBeFalse()
        ->and(phase1c7dHasForeign('products', 'pr_pa_ve_fk', ['parent_account_id', 'vendor_id'], 'vendors'))->toBeFalse()
        ->and(VendorProductOffering::query()->count())->toBe($offeringCount)
        ->and(OrganizationProductSource::query()->count())->toBe($sourceCount)
        ->and(OrganizationProductSourcePriceEvent::query()->count())->toBe($eventCount);

    if ($seedProduct !== null) {
        expect($seedProduct->organizationProducts()->first()->purchase_cost_micro_units)->toBe($purchaseCost);
    }
});

test('populated products.vendor_id blocks migration with no partial schema change', function () {
    phase1c7dRollbackDrop();
    expect(Schema::hasColumn('products', 'vendor_id'))->toBeTrue();

    $parent = ParentAccount::factory()->create();
    $vendor = Vendor::factory()->create(['parent_account_id' => $parent->id]);
    $productId = DB::table('products')->insertGetId([
        'parent_account_id' => $parent->id,
        'name' => 'Blocking Legacy Product',
        'product_family' => ProductFamily::Other->value,
        'item_kind' => ItemKind::Product->value,
        'sku' => 'BLOCK-LEGACY-'.uniqid(),
        'unit_of_measure' => UnitOfMeasure::Each->value,
        'true_cost_micro_units' => 0,
        'markup_basis_points' => 0,
        'vendor_id' => $vendor->id,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $offeringBefore = VendorProductOffering::query()->count();
    $sourceBefore = OrganizationProductSource::query()->count();

    expect(fn () => Artisan::call('migrate', ['--force' => true]))
        ->toThrow(RuntimeException::class, '1 product(s) still reference a vendor');

    expect(Schema::hasColumn('products', 'vendor_id'))->toBeTrue()
        ->and(phase1c7dHasIndex('products', 'products_vendor_id_index'))->toBeTrue()
        ->and((int) DB::table('products')->where('id', $productId)->value('vendor_id'))->toBe($vendor->id)
        ->and(DB::table('migrations')->where('migration', PHASE_1C7D_DROP_LEGACY_VENDOR_ID)->exists())->toBeFalse()
        ->and(VendorProductOffering::query()->count())->toBe($offeringBefore)
        ->and(OrganizationProductSource::query()->count())->toBe($sourceBefore);

    if (Schema::getConnection()->getDriverName() === 'mysql') {
        expect(phase1c7dHasForeign('products', 'products_vendor_id_foreign', ['vendor_id'], 'vendors'))->toBeTrue()
            ->and(phase1c7dHasIndex('products', 'products_parent_account_id_vendor_id_index'))->toBeTrue();
    }

    DB::table('products')->where('id', $productId)->update(['vendor_id' => null]);
    Artisan::call('migrate', ['--force' => true]);
    expect(Schema::hasColumn('products', 'vendor_id'))->toBeFalse();
});
