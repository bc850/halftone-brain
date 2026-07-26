<?php

use App\Enums\ItemKind;
use App\Enums\UnitOfMeasure;
use App\Enums\VendorProductOfferingStatus;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\OrganizationProductSource;
use App\Models\OrganizationProductSourcePriceEvent;
use App\Models\ParentAccount;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\VendorProductOffering;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

const PHASE_1C7A_MIGRATIONS = [
    '2026_07_26_173940_create_vendor_product_offerings_table',
    '2026_07_26_173941_create_organization_product_sources_table',
    '2026_07_26_173942_add_preferred_source_id_to_organization_products_table',
    '2026_07_26_173943_create_organization_product_source_price_events_table',
];

function phase1c7aHasIndex(string $table, string $indexName, bool $unique = false): bool
{
    foreach (Schema::getIndexes($table) as $index) {
        if (($index['name'] ?? null) !== $indexName) {
            continue;
        }

        if ($unique && ! ($index['unique'] ?? false)) {
            return false;
        }

        return true;
    }

    return false;
}

function phase1c7aHasForeign(string $table, string $name, array $columns, string $foreignTable): bool
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

function phase1c7aRollback(): void
{
    // Phase 1C.7D (1) + Phase 1C.7A (4).
    Artisan::call('migrate:rollback', ['--step' => 5, '--force' => true]);
}

function phase1c7aRemigrate(): void
{
    Artisan::call('migrate', ['--force' => true]);
}

/**
 * @return array{
 *     parent: ParentAccount,
 *     organization: Organization,
 *     product: Product,
 *     vendor: Vendor,
 *     offering: VendorProductOffering,
 *     organizationProduct: OrganizationProduct
 * }
 */
function phase1c7aSeedGraph(): array
{
    $parent = ParentAccount::factory()->create();
    $organization = Organization::factory()->create([
        'parent_account_id' => $parent->id,
    ]);
    $product = Product::factory()->create([
        'parent_account_id' => $parent->id,
        'item_kind' => ItemKind::Material,
        'sku' => 'MAT-ACM-3MM-48X96-'.uniqid(),
    ]);
    $vendor = Vendor::factory()->create([
        'parent_account_id' => $parent->id,
        'name' => 'Grimco',
    ]);
    $organizationProduct = OrganizationProduct::factory()->create([
        'parent_account_id' => $parent->id,
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'is_purchasable' => true,
        'purchase_unit_of_measure' => UnitOfMeasure::Sheet,
        'purchase_cost_micro_units' => Money::dollarsToMicroUnits('80'),
    ]);
    $offering = VendorProductOffering::factory()->create([
        'parent_account_id' => $parent->id,
        'product_id' => $product->id,
        'vendor_id' => $vendor->id,
        'vendor_sku' => 'GRIMCO-ACM-'.uniqid(),
        'purchase_uom' => UnitOfMeasure::Sheet,
        'package_quantity_scaled' => ComponentCostEstimator::QUANTITY_SCALE_FACTOR,
    ]);

    return compact('parent', 'organization', 'product', 'vendor', 'offering', 'organizationProduct');
}

test('phase 1c7a vendor offering schema indexes and foreign keys', function () {
    expect(Schema::hasTable('vendor_product_offerings'))->toBeTrue()
        ->and(Schema::hasColumns('vendor_product_offerings', [
            'id',
            'parent_account_id',
            'product_id',
            'vendor_id',
            'vendor_sku',
            'vendor_description',
            'manufacturer',
            'manufacturer_part_number',
            'product_url',
            'purchase_uom',
            'package_quantity_scaled',
            'minimum_order_quantity_scaled',
            'lead_time_days',
            'status',
            'discontinued_at',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(phase1c7aHasIndex('vendor_product_offerings', 'vpo_pa_vendor_sku_uidx', unique: true))->toBeTrue()
        ->and(phase1c7aHasIndex('vendor_product_offerings', 'vpo_pa_id_uidx', unique: true))->toBeTrue()
        ->and(phase1c7aHasForeign(
            'vendor_product_offerings',
            'vpo_pa_product_fk',
            ['parent_account_id', 'product_id'],
            'products',
        ))->toBeTrue()
        ->and(phase1c7aHasForeign(
            'vendor_product_offerings',
            'vpo_pa_vendor_fk',
            ['parent_account_id', 'vendor_id'],
            'vendors',
        ))->toBeTrue();
});

test('phase 1c7a allows multiple offerings for one product vendor with distinct skus', function () {
    $g = phase1c7aSeedGraph();

    $second = VendorProductOffering::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'product_id' => $g['product']->id,
        'vendor_id' => $g['vendor']->id,
        'vendor_sku' => 'GRIMCO-PACK-10',
        'package_quantity_scaled' => ComponentCostEstimator::quantityToScaled('10'),
    ]);

    expect(VendorProductOffering::query()->where('product_id', $g['product']->id)->count())->toBe(2)
        ->and($second->package_quantity_scaled)->toBe(10_000_000)
        ->and($g['product']->fresh()->sku)->toBe($g['product']->sku);
});

test('phase 1c7a rejects duplicate vendor sku within one vendor', function () {
    $g = phase1c7aSeedGraph();

    expect(fn () => VendorProductOffering::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'product_id' => $g['product']->id,
        'vendor_id' => $g['vendor']->id,
        'vendor_sku' => $g['offering']->vendor_sku,
    ]))->toThrow(QueryException::class);
});

test('phase 1c7a allows same vendor sku text at different vendors', function () {
    $g = phase1c7aSeedGraph();
    $otherVendor = Vendor::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'name' => 'Other Supply',
    ]);

    $offering = VendorProductOffering::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'product_id' => $g['product']->id,
        'vendor_id' => $otherVendor->id,
        'vendor_sku' => $g['offering']->vendor_sku,
    ]);

    expect($offering->vendor_sku)->toBe($g['offering']->vendor_sku);
});

test('phase 1c7a rejects cross parent offering and zero package quantity', function () {
    $g = phase1c7aSeedGraph();
    $otherParent = ParentAccount::factory()->create();
    $otherProduct = Product::factory()->create([
        'parent_account_id' => $otherParent->id,
        'sku' => 'OTHER-'.uniqid(),
    ]);

    expect(fn () => VendorProductOffering::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'product_id' => $otherProduct->id,
        'vendor_id' => $g['vendor']->id,
        'vendor_sku' => 'CROSS-PARENT',
    ]))->toThrow(ValidationException::class);

    expect(fn () => VendorProductOffering::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'product_id' => $g['product']->id,
        'vendor_id' => $g['vendor']->id,
        'vendor_sku' => 'ZERO-PACK',
        'package_quantity_scaled' => 0,
    ]))->toThrow(ValidationException::class);
});

test('phase 1c7a organization product source schema defaults and uniqueness', function () {
    expect(Schema::hasTable('organization_product_sources'))->toBeTrue()
        ->and(Schema::hasColumns('organization_product_sources', [
            'id',
            'parent_account_id',
            'organization_id',
            'organization_product_id',
            'vendor_product_offering_id',
            'current_package_price_micro_units',
            'currency_code',
            'price_version',
            'is_active',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(phase1c7aHasIndex('organization_product_sources', 'opsrc_op_offering_uidx', unique: true))->toBeTrue()
        ->and(phase1c7aHasIndex('organization_product_sources', 'opsrc_op_id_uidx', unique: true))->toBeTrue();

    $g = phase1c7aSeedGraph();
    $source = OrganizationProductSource::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'organization_product_id' => $g['organizationProduct']->id,
        'vendor_product_offering_id' => $g['offering']->id,
    ]);

    expect($source->currency_code)->toBe('USD')
        ->and($source->price_version)->toBe(1)
        ->and($source->is_active)->toBeTrue()
        ->and($source->current_package_price_micro_units)->toBeNull()
        ->and($g['organizationProduct']->fresh()->purchase_cost_micro_units)
        ->toBe(Money::dollarsToMicroUnits('80'));

    expect(fn () => OrganizationProductSource::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'organization_product_id' => $g['organizationProduct']->id,
        'vendor_product_offering_id' => $g['offering']->id,
    ]))->toThrow(QueryException::class);
});

test('phase 1c7a rejects cross org and cross parent sources', function () {
    $g = phase1c7aSeedGraph();
    $otherOrg = Organization::factory()->create([
        'parent_account_id' => $g['parent']->id,
    ]);
    $otherOp = OrganizationProduct::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $otherOrg->id,
        'product_id' => $g['product']->id,
    ]);

    expect(fn () => OrganizationProductSource::query()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'organization_product_id' => $otherOp->id,
        'vendor_product_offering_id' => $g['offering']->id,
        'currency_code' => 'USD',
        'price_version' => 1,
        'is_active' => true,
    ]))->toThrow(ValidationException::class);

    $otherParent = ParentAccount::factory()->create();
    $otherVendor = Vendor::factory()->create(['parent_account_id' => $otherParent->id]);
    $otherProduct = Product::factory()->create([
        'parent_account_id' => $otherParent->id,
        'sku' => 'XP-'.uniqid(),
    ]);
    $foreignOffering = VendorProductOffering::factory()->create([
        'parent_account_id' => $otherParent->id,
        'product_id' => $otherProduct->id,
        'vendor_id' => $otherVendor->id,
        'vendor_sku' => 'FOREIGN-SKU',
    ]);

    expect(fn () => OrganizationProductSource::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'organization_product_id' => $g['organizationProduct']->id,
        'vendor_product_offering_id' => $foreignOffering->id,
    ]))->toThrow(ValidationException::class);
});

test('phase 1c7a preferred source must belong to organization product and may be null', function () {
    expect(Schema::hasColumn('organization_products', 'preferred_source_id'))->toBeTrue();

    // Composite preferred FK is MySQL-only (circular with sources under SQLite/RefreshDatabase).
    if (Schema::getConnection()->getDriverName() === 'mysql') {
        expect(phase1c7aHasForeign(
            'organization_products',
            'op_preferred_source_fk',
            ['id', 'preferred_source_id'],
            'organization_product_sources',
        ))->toBeTrue();
    }

    $g = phase1c7aSeedGraph();
    $op = $g['organizationProduct'];

    expect($op->preferred_source_id)->toBeNull();

    $source = OrganizationProductSource::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'organization_product_id' => $op->id,
        'vendor_product_offering_id' => $g['offering']->id,
        'current_package_price_micro_units' => Money::dollarsToMicroUnits('800'),
    ]);

    $op->update(['preferred_source_id' => $source->id]);
    expect($op->fresh()->preferredSource->is($source))->toBeTrue();

    $otherProduct = Product::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'sku' => 'OTHER-PROD-'.uniqid(),
    ]);
    $otherOp = OrganizationProduct::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'product_id' => $otherProduct->id,
    ]);
    $otherOffering = VendorProductOffering::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'product_id' => $otherProduct->id,
        'vendor_id' => $g['vendor']->id,
        'vendor_sku' => 'OTHER-OFFER',
    ]);
    $otherSource = OrganizationProductSource::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'organization_product_id' => $otherOp->id,
        'vendor_product_offering_id' => $otherOffering->id,
    ]);

    expect(fn () => $op->update(['preferred_source_id' => $otherSource->id]))
        ->toThrow(ValidationException::class);

    if (Schema::getConnection()->getDriverName() === 'mysql') {
        expect(fn () => DB::table('organization_products')->where('id', $op->id)->update([
            'preferred_source_id' => $otherSource->id,
        ]))->toThrow(QueryException::class);
    }

    $op->update(['preferred_source_id' => null]);
    expect($op->fresh()->preferred_source_id)->toBeNull();
});

test('phase 1c7a append only price events have no updated at', function () {
    expect(Schema::hasTable('organization_product_source_price_events'))->toBeTrue()
        ->and(Schema::hasColumn('organization_product_source_price_events', 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn('organization_product_source_price_events', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumns('organization_product_source_price_events', [
            'package_price_micro_units',
            'effective_purchase_unit_cost_micro_units',
            'recorded_at',
            'note',
            'actor_user_id',
        ]))->toBeTrue();

    $g = phase1c7aSeedGraph();
    $source = OrganizationProductSource::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'organization_product_id' => $g['organizationProduct']->id,
        'vendor_product_offering_id' => $g['offering']->id,
    ]);

    $event = OrganizationProductSourcePriceEvent::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'organization_product_source_id' => $source->id,
        'package_price_micro_units' => Money::dollarsToMicroUnits('800'),
        'effective_purchase_unit_cost_micro_units' => Money::dollarsToMicroUnits('80'),
    ]);

    expect(fn () => $event->update(['note' => 'changed']))
        ->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $event->delete())
        ->toThrow(LogicException::class, 'append-only');
});

test('phase 1c7a restrict deletes prevent removing used offerings and sources', function () {
    $g = phase1c7aSeedGraph();
    $source = OrganizationProductSource::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'organization_product_id' => $g['organizationProduct']->id,
        'vendor_product_offering_id' => $g['offering']->id,
    ]);
    $g['organizationProduct']->update(['preferred_source_id' => $source->id]);

    expect(fn () => $g['offering']->delete())->toThrow(QueryException::class)
        ->and(fn () => $g['organizationProduct']->delete())->toThrow(QueryException::class);

    // Preferred → source RESTRICT is MySQL composite FK; SQLite uses model guards only.
    if (Schema::getConnection()->getDriverName() === 'mysql') {
        expect(fn () => $source->delete())->toThrow(QueryException::class);
    }
});

test('phase 1c7a rollback removes preferred then sources then offerings and remigrates', function () {
    $g = phase1c7aSeedGraph();
    OrganizationProductSource::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'organization_product_id' => $g['organizationProduct']->id,
        'vendor_product_offering_id' => $g['offering']->id,
    ]);

    expect(Schema::hasTable('organization_product_components'))->toBeTrue()
        ->and(Schema::hasColumn('organization_products', 'purchase_cost_micro_units'))->toBeTrue()
        ->and(Schema::hasColumn('products', 'vendor_id'))->toBeFalse()
        ->and(Schema::hasColumn('products', 'vendor_sku'))->toBeFalse();

    phase1c7aRollback();

    expect(Schema::hasTable('organization_product_source_price_events'))->toBeFalse()
        ->and(Schema::hasColumn('organization_products', 'preferred_source_id'))->toBeFalse()
        ->and(Schema::hasTable('organization_product_sources'))->toBeFalse()
        ->and(Schema::hasTable('vendor_product_offerings'))->toBeFalse()
        ->and(Schema::hasTable('organization_product_components'))->toBeTrue()
        ->and(Schema::hasColumn('organization_products', 'purchase_cost_micro_units'))->toBeTrue()
        ->and(Schema::hasColumn('organization_products', 'components_version'))->toBeTrue()
        ->and(Schema::hasColumn('products', 'vendor_id'))->toBeTrue()
        ->and(Schema::hasColumn('products', 'vendor_sku'))->toBeTrue()
        ->and(DB::table('organization_products')->where('id', $g['organizationProduct']->id)->exists())->toBeTrue();

    phase1c7aRemigrate();

    expect(Schema::hasTable('vendor_product_offerings'))->toBeTrue()
        ->and(Schema::hasTable('organization_product_sources'))->toBeTrue()
        ->and(Schema::hasColumn('organization_products', 'preferred_source_id'))->toBeTrue()
        ->and(Schema::hasTable('organization_product_source_price_events'))->toBeTrue()
        ->and(Schema::hasColumn('products', 'vendor_id'))->toBeFalse()
        ->and(Schema::hasColumn('products', 'vendor_sku'))->toBeFalse();
});

test('phase 1c7a schema setup does not mutate purchase cost or product master identity', function () {
    $parent = ParentAccount::factory()->create();
    $product = Product::factory()->create([
        'parent_account_id' => $parent->id,
        'sku' => 'LEGACY-'.uniqid(),
    ]);

    expect(Schema::hasColumn('products', 'vendor_id'))->toBeFalse()
        ->and(Schema::hasColumn('products', 'vendor_sku'))->toBeFalse()
        ->and(Schema::hasColumn('vendor_product_offerings', 'vendor_sku'))->toBeTrue();

    $organization = Organization::factory()->create([
        'parent_account_id' => $parent->id,
    ]);
    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $parent->id,
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'purchase_cost_micro_units' => Money::dollarsToMicroUnits('12.50'),
    ]);

    VendorProductOffering::factory()->create([
        'parent_account_id' => $parent->id,
        'product_id' => $product->id,
        'vendor_id' => Vendor::factory()->create(['parent_account_id' => $parent->id])->id,
        'vendor_sku' => 'NO-SIDE-EFFECT',
    ]);

    expect($op->fresh()->purchase_cost_micro_units)->toBe(Money::dollarsToMicroUnits('12.50'))
        ->and($product->fresh()->sku)->toBe($product->sku)
        ->and(VendorProductOfferingStatus::Active->value)->toBe('active');
});
