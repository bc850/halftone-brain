<?php

use App\Enums\InventoryTrackingMode;
use App\Enums\ItemKind;
use App\Enums\ProductFamily;
use App\Enums\UnitOfMeasure;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\OrganizationProductUnitConversion;
use App\Models\ParentAccount;
use App\Models\Product;
use App\Support\Catalog\UnitConversion;
use App\Support\Pricing\PricingCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

const PHASE_1C4_MIGRATIONS = [
    '2026_07_25_194256_add_item_kind_to_products_table',
    '2026_07_25_194257_add_classification_and_unit_fields_to_organization_products_table',
    '2026_07_25_194258_create_organization_product_unit_conversions_table',
];

function phase1c4HasIndex(string $table, string $indexName, bool $unique = false): bool
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

function phase1c4HasForeign(string $table, string $name, array $columns, string $foreignTable): bool
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

function phase1c4Rollback(): void
{
    // Phase 1C.7D (1) + Phase 1C.7A (4) + Phase 1C.6A (2) + Phase 1C.4 (3).
    Artisan::call('migrate:rollback', ['--step' => 10, '--force' => true]);
}

function phase1c4Remigrate(): void
{
    Artisan::call('migrate', ['--force' => true]);
}

test('phase 1c4 item_kind exists with product default and parent kind index', function () {
    expect(Schema::hasColumn('products', 'item_kind'))->toBeTrue()
        ->and(phase1c4HasIndex('products', 'pr_pa_kind_idx'))->toBeTrue();

    $parent = ParentAccount::factory()->create();
    $id = DB::table('products')->insertGetId([
        'parent_account_id' => $parent->id,
        'name' => 'Default Kind Product',
        'sku' => 'KIND-DEFAULT-1',
        'product_family' => 'other',
        'unit_of_measure' => 'each',
        'true_cost_micro_units' => 0,
        'markup_basis_points' => 0,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('products')->where('id', $id)->value('item_kind'))->toBe('product');

    $material = Product::factory()->material()->create([
        'parent_account_id' => $parent->id,
        'product_family' => ProductFamily::Signage,
        'sku' => 'ACM-SHEET-1',
    ]);
    $service = Product::factory()->service()->create([
        'parent_account_id' => $parent->id,
        'sku' => 'INSTALL-1',
    ]);

    expect($material->item_kind)->toBe(ItemKind::Material)
        ->and($material->product_family)->toBe(ProductFamily::Signage)
        ->and($service->item_kind)->toBe(ItemKind::Service)
        ->and($service->product_family)->toBe(ProductFamily::Service);
});

test('phase 1c4 organization product classification defaults preserve sellable product workflow', function () {
    expect(Schema::hasColumns('organization_products', [
        'is_sellable',
        'is_purchasable',
        'inventory_tracking_mode',
        'purchase_unit_of_measure',
        'stock_unit_of_measure',
        'usage_unit_of_measure',
    ]))->toBeTrue();

    $op = OrganizationProduct::factory()->create();

    expect($op->is_sellable)->toBeTrue()
        ->and($op->is_purchasable)->toBeFalse()
        ->and($op->inventory_tracking_mode)->toBe(InventoryTrackingMode::None)
        ->and($op->purchase_unit_of_measure)->toBeNull()
        ->and($op->stock_unit_of_measure)->toBeNull()
        ->and($op->usage_unit_of_measure)->toBeNull()
        ->and($op->product->item_kind)->toBe(ItemKind::Product);

    $materialOp = OrganizationProduct::factory()->create([
        'is_sellable' => false,
        'is_purchasable' => true,
        'inventory_tracking_mode' => InventoryTrackingMode::PeriodicExternal,
        'purchase_unit_of_measure' => UnitOfMeasure::Sheet,
        'stock_unit_of_measure' => UnitOfMeasure::Sheet,
        'usage_unit_of_measure' => UnitOfMeasure::SquareFoot,
        'product_id' => Product::factory()->material()->create([
            'parent_account_id' => $op->parent_account_id,
        ])->id,
        'parent_account_id' => $op->parent_account_id,
        'organization_id' => $op->organization_id,
    ]);

    expect($materialOp->inventory_tracking_mode)->toBe(InventoryTrackingMode::PeriodicExternal)
        ->and($materialOp->purchase_unit_of_measure)->toBe(UnitOfMeasure::Sheet);
});

test('phase 1c4 rejects unapproved inventory tracking modes through application enum', function () {
    expect(fn () => InventoryTrackingMode::from('perpetual_internal'))
        ->toThrow(ValueError::class);

    expect(InventoryTrackingMode::cases())->toHaveCount(2)
        ->and(collect(InventoryTrackingMode::cases())->map->value->all())
        ->toBe(['none', 'periodic_external']);
});

test('phase 1c4 unit conversion stores exact sheet to square foot ratio', function () {
    expect(Schema::hasTable('organization_product_unit_conversions'))->toBeTrue()
        ->and(phase1c4HasIndex('organization_product_unit_conversions', 'opuc_op_from_to_uidx', unique: true))->toBeTrue()
        ->and(phase1c4HasForeign(
            'organization_product_unit_conversions',
            'opuc_pa_org_fk',
            ['parent_account_id', 'organization_id'],
            'organizations',
        ))->toBeTrue()
        ->and(phase1c4HasForeign(
            'organization_product_unit_conversions',
            'opuc_org_op_fk',
            ['organization_id', 'organization_product_id'],
            'organization_products',
        ))->toBeTrue();

    $op = OrganizationProduct::factory()->create([
        'purchase_unit_of_measure' => UnitOfMeasure::Sheet,
        'stock_unit_of_measure' => UnitOfMeasure::Sheet,
        'usage_unit_of_measure' => UnitOfMeasure::SquareFoot,
    ]);

    $conversion = OrganizationProductUnitConversion::factory()->create([
        'organization_product_id' => $op->id,
        'parent_account_id' => $op->parent_account_id,
        'organization_id' => $op->organization_id,
        'from_unit' => UnitOfMeasure::Sheet,
        'to_unit' => UnitOfMeasure::SquareFoot,
        'numerator' => 32,
        'denominator' => 1,
    ]);

    expect($conversion->numerator)->toBe(32)
        ->and($conversion->denominator)->toBe(1)
        ->and($conversion->toValueObject()->convert('1', 0))->toBe('32')
        ->and($conversion->toValueObject()->convert('2.5', 4))->toBe('80.0000');
});

test('phase 1c4 conversion rejects duplicates cross-org parent mismatch and invalid ratios', function () {
    $op = OrganizationProduct::factory()->create();
    $otherOrg = Organization::factory()->create([
        'parent_account_id' => $op->parent_account_id,
    ]);
    $otherParent = ParentAccount::factory()->create();
    $foreignOp = OrganizationProduct::factory()->create();

    OrganizationProductUnitConversion::factory()->create([
        'organization_product_id' => $op->id,
        'parent_account_id' => $op->parent_account_id,
        'organization_id' => $op->organization_id,
        'from_unit' => UnitOfMeasure::Sheet,
        'to_unit' => UnitOfMeasure::SquareFoot,
        'numerator' => 32,
        'denominator' => 1,
    ]);

    expect(fn () => OrganizationProductUnitConversion::factory()->create([
        'organization_product_id' => $op->id,
        'parent_account_id' => $op->parent_account_id,
        'organization_id' => $op->organization_id,
        'from_unit' => UnitOfMeasure::Sheet,
        'to_unit' => UnitOfMeasure::SquareFoot,
        'numerator' => 32,
        'denominator' => 1,
    ]))->toThrow(QueryException::class);

    $siblingOp = OrganizationProduct::factory()->create([
        'parent_account_id' => $op->parent_account_id,
        'organization_id' => $op->organization_id,
    ]);

    expect(OrganizationProductUnitConversion::factory()->create([
        'organization_product_id' => $siblingOp->id,
        'parent_account_id' => $siblingOp->parent_account_id,
        'organization_id' => $siblingOp->organization_id,
        'from_unit' => UnitOfMeasure::Sheet,
        'to_unit' => UnitOfMeasure::SquareFoot,
        'numerator' => 32,
        'denominator' => 1,
    ]))->toBeInstanceOf(OrganizationProductUnitConversion::class);

    expect(fn () => OrganizationProductUnitConversion::query()->create([
        'organization_product_id' => $op->id,
        'parent_account_id' => $op->parent_account_id,
        'organization_id' => $otherOrg->id,
        'from_unit' => UnitOfMeasure::Sheet->value,
        'to_unit' => UnitOfMeasure::Yard->value,
        'numerator' => 1,
        'denominator' => 1,
        'is_active' => true,
    ]))->toThrow(ValidationException::class);

    expect(fn () => OrganizationProductUnitConversion::query()->create([
        'organization_product_id' => $op->id,
        'parent_account_id' => $otherParent->id,
        'organization_id' => $op->organization_id,
        'from_unit' => UnitOfMeasure::Sheet->value,
        'to_unit' => UnitOfMeasure::Inch->value,
        'numerator' => 1,
        'denominator' => 1,
        'is_active' => true,
    ]))->toThrow(ValidationException::class);

    expect(fn () => OrganizationProductUnitConversion::query()->create([
        'organization_product_id' => $foreignOp->id,
        'parent_account_id' => $op->parent_account_id,
        'organization_id' => $op->organization_id,
        'from_unit' => UnitOfMeasure::Roll->value,
        'to_unit' => UnitOfMeasure::Yard->value,
        'numerator' => 1,
        'denominator' => 1,
        'is_active' => true,
    ]))->toThrow(ValidationException::class);

    expect(fn () => OrganizationProductUnitConversion::factory()->create([
        'organization_product_id' => $op->id,
        'parent_account_id' => $op->parent_account_id,
        'organization_id' => $op->organization_id,
        'from_unit' => UnitOfMeasure::Roll,
        'to_unit' => UnitOfMeasure::Yard,
        'numerator' => 0,
        'denominator' => 1,
    ]))->toThrow(ValidationException::class);

    expect(fn () => OrganizationProductUnitConversion::factory()->create([
        'organization_product_id' => $op->id,
        'parent_account_id' => $op->parent_account_id,
        'organization_id' => $op->organization_id,
        'from_unit' => UnitOfMeasure::Yard,
        'to_unit' => UnitOfMeasure::Foot,
        'numerator' => 3,
        'denominator' => 0,
    ]))->toThrow(ValidationException::class);

    expect(fn () => new UnitConversion(UnitOfMeasure::Sheet, UnitOfMeasure::SquareFoot, -1, 1))
        ->toThrow(InvalidArgumentException::class);
});

test('phase 1c4 existing catalog creation and pricing still work', function () {
    $ctx = createTenantUser('owner', 'parent_owner');

    $this->actingAs($ctx['user'])
        ->post(route('org.products.store', $ctx['organization']), [
            'name' => '48x96 ACM Sign',
            'sku' => 'ACM-SIGN-1C4',
            'product_family' => ProductFamily::Signage->value,
            'item_kind' => 'product',
            'unit_of_measure' => UnitOfMeasure::Each->value,
            'is_active' => true,
            'is_available' => true,
            'material_cost' => '40',
            'labor_cost' => '30',
            'overhead_mode' => 'fixed',
            'overhead_amount' => '10',
            'overhead_rate_percent' => '0',
            'pricing_method' => 'markup',
            'markup_percent' => '50',
            'target_margin_percent' => '0',
            'fixed_price' => null,
            'minimum_price' => null,
            'allow_price_override' => false,
        ])
        ->assertRedirect();

    $product = Product::query()->where('sku', 'ACM-SIGN-1C4')->firstOrFail();
    $op = OrganizationProduct::query()->where('product_id', $product->id)->firstOrFail();

    expect($product->item_kind)->toBe(ItemKind::Product)
        ->and($op->is_sellable)->toBeTrue()
        ->and($op->is_purchasable)->toBeFalse()
        ->and($op->inventory_tracking_mode)->toBe(InventoryTrackingMode::None)
        ->and($op->unitConversions()->count())->toBe(0)
        ->and((new PricingCalculator)->calculate($op->toPricingInput())->finalUnitPriceCents)->toBe(12000);
});

test('phase 1c4 rollback removes only 1c4 schema and remigrates', function () {
    $parent = ParentAccount::factory()->create();
    $organization = Organization::factory()->create([
        'parent_account_id' => $parent->id,
    ]);
    $productId = Product::factory()->create([
        'parent_account_id' => $parent->id,
        'sku' => 'SURVIVE-1C4',
        'item_kind' => ItemKind::Product,
    ])->id;
    $opId = OrganizationProduct::factory()->create([
        'parent_account_id' => $parent->id,
        'organization_id' => $organization->id,
        'product_id' => $productId,
    ])->id;

    OrganizationProductUnitConversion::factory()->create([
        'organization_product_id' => $opId,
        'parent_account_id' => $parent->id,
        'organization_id' => $organization->id,
    ]);

    phase1c4Rollback();

    expect(Schema::hasColumn('products', 'item_kind'))->toBeFalse()
        ->and(Schema::hasColumn('organization_products', 'is_sellable'))->toBeFalse()
        ->and(Schema::hasTable('organization_product_unit_conversions'))->toBeFalse()
        ->and(Schema::hasTable('organization_products'))->toBeTrue()
        ->and(Schema::hasColumn('products', 'product_family'))->toBeTrue()
        ->and(DB::table('products')->where('id', $productId)->exists())->toBeTrue()
        ->and(DB::table('organization_products')->where('id', $opId)->exists())->toBeTrue();

    phase1c4Remigrate();

    expect(Schema::hasColumn('products', 'item_kind'))->toBeTrue()
        ->and(Schema::hasColumn('organization_products', 'is_sellable'))->toBeTrue()
        ->and(Schema::hasTable('organization_product_unit_conversions'))->toBeTrue()
        ->and(DB::table('products')->where('id', $productId)->value('item_kind'))->toBe('product')
        ->and(DB::table('organization_products')->where('id', $opId)->value('is_sellable'))->toBe(1);
});
