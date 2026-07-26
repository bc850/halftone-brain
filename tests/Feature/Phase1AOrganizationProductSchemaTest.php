<?php

use App\Enums\OverheadMode;
use App\Enums\PricingMethod;
use App\Enums\ProductFamily;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\ParentAccount;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const PHASE_1A_MIGRATIONS = [
    '2026_07_24_040323_create_organization_products_table',
    '2026_07_24_040324_add_product_family_and_parent_scoped_sku_unique_to_products_table',
];

function phase1aHasIndex(string $table, string $indexName, bool $unique = false): bool
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

function phase1aHasForeign(string $table, string $name, array $columns, string $foreignTable): bool
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

function phase1aRollback(): void
{
    // Phase 1C.7D (1) + Phase 1C.7A (4) + Phase 1C.6A (2) + Phase 1C.4 (3) + Phase 1A (2).
    Artisan::call('migrate:rollback', ['--step' => 12, '--force' => true]);
}

function phase1aRemigrate(): void
{
    Artisan::call('migrate', ['--force' => true]);
}

test('phase 1a product_family exists with other default and parent family index', function () {
    expect(Schema::hasColumn('products', 'product_family'))->toBeTrue()
        ->and(phase1aHasIndex('products', 'pr_pa_family_idx'))->toBeTrue();

    $parent = ParentAccount::factory()->create();
    $id = DB::table('products')->insertGetId([
        'parent_account_id' => $parent->id,
        'name' => 'Default Family Product',
        'sku' => 'FAM-DEFAULT-1',
        'unit_of_measure' => 'each',
        'true_cost_micro_units' => 0,
        'markup_basis_points' => 0,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('products')->where('id', $id)->value('product_family'))->toBe('other');
});

test('phase 1a sku is unique per parent and reusable across parents', function () {
    expect(phase1aHasIndex('products', 'pr_pa_sku_uidx', unique: true))->toBeTrue()
        ->and(phase1aHasIndex('products', 'products_sku_unique', unique: true))->toBeFalse();

    $parentA = ParentAccount::factory()->create();
    $parentB = ParentAccount::factory()->create();

    Product::factory()->create([
        'parent_account_id' => $parentA->id,
        'sku' => 'SHARED-SKU-1',
        'product_family' => ProductFamily::Signage,
    ]);

    expect(fn () => Product::factory()->create([
        'parent_account_id' => $parentA->id,
        'sku' => 'SHARED-SKU-1',
    ]))->toThrow(QueryException::class);

    expect(Product::factory()->create([
        'parent_account_id' => $parentB->id,
        'sku' => 'SHARED-SKU-1',
        'product_family' => ProductFamily::Apparel,
    ])->sku)->toBe('SHARED-SKU-1');
});

test('phase 1a organization product schema defaults and constraints', function () {
    expect(Schema::hasTable('organization_products'))->toBeTrue()
        ->and(phase1aHasIndex('organization_products', 'op_org_product_uidx', unique: true))->toBeTrue()
        ->and(phase1aHasIndex('organization_products', 'op_org_id_uidx', unique: true))->toBeTrue()
        ->and(phase1aHasForeign(
            'organization_products',
            'op_pa_org_fk',
            ['parent_account_id', 'organization_id'],
            'organizations'
        ))->toBeTrue()
        ->and(phase1aHasForeign(
            'organization_products',
            'op_pa_pr_fk',
            ['parent_account_id', 'product_id'],
            'products'
        ))->toBeTrue();

    $parent = ParentAccount::factory()->create();
    $otherParent = ParentAccount::factory()->create();
    $org = Organization::factory()->create(['parent_account_id' => $parent->id]);
    $otherOrg = Organization::factory()->create(['parent_account_id' => $otherParent->id]);
    $product = Product::factory()->create(['parent_account_id' => $parent->id]);
    $foreignProduct = Product::factory()->create(['parent_account_id' => $otherParent->id]);

    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $parent->id,
        'organization_id' => $org->id,
        'product_id' => $product->id,
    ]);

    expect($op->pricing_method)->toBe(PricingMethod::Markup)
        ->and($op->overhead_mode)->toBe(OverheadMode::None)
        ->and($op->currency_code)->toBe('USD')
        ->and($op->material_cost_micro_units)->toBe(0)
        ->and($op->labor_cost_micro_units)->toBe(0)
        ->and($op->overhead_amount_micro_units)->toBe(0)
        ->and($op->overhead_rate_basis_points)->toBe(0)
        ->and($op->markup_basis_points)->toBe(0)
        ->and($op->target_margin_basis_points)->toBe(0)
        ->and($op->fixed_price_cents)->toBeNull()
        ->and($op->minimum_price_cents)->toBeNull()
        ->and($op->display_name)->toBeNull()
        ->and($op->lead_time_days)->toBeNull()
        ->and($op->notes)->toBeNull()
        ->and($op->is_available)->toBeTrue()
        ->and($op->allow_price_override)->toBeFalse()
        ->and($op->pricing_version)->toBe(1);

    expect(fn () => OrganizationProduct::factory()->create([
        'parent_account_id' => $parent->id,
        'organization_id' => $org->id,
        'product_id' => $product->id,
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('organization_products')->insert([
        'parent_account_id' => $parent->id,
        'organization_id' => $org->id,
        'product_id' => $foreignProduct->id,
        'is_available' => true,
        'material_cost_micro_units' => 0,
        'labor_cost_micro_units' => 0,
        'overhead_mode' => 'none',
        'overhead_amount_micro_units' => 0,
        'overhead_rate_basis_points' => 0,
        'pricing_method' => 'markup',
        'markup_basis_points' => 0,
        'target_margin_basis_points' => 0,
        'allow_price_override' => false,
        'currency_code' => 'USD',
        'pricing_version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('organization_products')->insert([
        'parent_account_id' => $otherParent->id,
        'organization_id' => $otherOrg->id,
        'product_id' => $product->id,
        'is_available' => true,
        'material_cost_micro_units' => 0,
        'labor_cost_micro_units' => 0,
        'overhead_mode' => 'none',
        'overhead_amount_micro_units' => 0,
        'overhead_rate_basis_points' => 0,
        'pricing_method' => 'markup',
        'markup_basis_points' => 0,
        'target_margin_basis_points' => 0,
        'allow_price_override' => false,
        'currency_code' => 'USD',
        'pricing_version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('organization_products')->insert([
        'parent_account_id' => null,
        'organization_id' => $org->id,
        'product_id' => $product->id,
        'is_available' => true,
        'material_cost_micro_units' => 0,
        'labor_cost_micro_units' => 0,
        'overhead_mode' => 'none',
        'overhead_amount_micro_units' => 0,
        'overhead_rate_basis_points' => 0,
        'pricing_method' => 'markup',
        'markup_basis_points' => 0,
        'target_margin_basis_points' => 0,
        'allow_price_override' => false,
        'currency_code' => 'USD',
        'pricing_version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('phase 1a retains legacy product cost and price columns', function () {
    expect(Schema::hasColumns('products', [
        'true_cost_micro_units',
        'markup_basis_points',
        'list_price_cents',
    ]))->toBeTrue();
});

test('phase 1a rollback removes additions and restores global sku uniqueness', function () {
    expect(Schema::hasTable('organization_products'))->toBeTrue()
        ->and(Schema::hasColumn('products', 'product_family'))->toBeTrue();

    phase1aRollback();

    expect(Schema::hasTable('organization_products'))->toBeFalse()
        ->and(Schema::hasColumn('products', 'product_family'))->toBeFalse()
        ->and(phase1aHasIndex('products', 'products_sku_unique', unique: true))->toBeTrue()
        ->and(phase1aHasIndex('products', 'pr_pa_sku_uidx', unique: true))->toBeFalse()
        ->and(Schema::hasTable('parent_accounts'))->toBeTrue()
        ->and(phase1aHasIndex('products', 'pr_pa_id_uidx', unique: true))->toBeTrue();

    phase1aRemigrate();

    expect(Schema::hasTable('organization_products'))->toBeTrue()
        ->and(Schema::hasColumn('products', 'product_family'))->toBeTrue()
        ->and(phase1aHasIndex('products', 'pr_pa_sku_uidx', unique: true))->toBeTrue();
});

test('phase 1a rollback aborts when cross-parent duplicate skus exist', function () {
    $parentA = ParentAccount::factory()->create();
    $parentB = ParentAccount::factory()->create();

    Product::factory()->create([
        'parent_account_id' => $parentA->id,
        'sku' => 'ROLLBACK-DUP-SKU',
    ]);
    Product::factory()->create([
        'parent_account_id' => $parentB->id,
        'sku' => 'ROLLBACK-DUP-SKU',
    ]);

    expect(fn () => Artisan::call('migrate:rollback', ['--step' => 12, '--force' => true]))
        ->toThrow(RuntimeException::class, 'duplicate SKUs across parents');

    expect(Schema::hasTable('organization_products'))->toBeTrue()
        ->and(Schema::hasColumn('products', 'product_family'))->toBeTrue()
        ->and(phase1aHasIndex('products', 'pr_pa_sku_uidx', unique: true))->toBeTrue()
        ->and(DB::table('migrations')->whereIn('migration', PHASE_1A_MIGRATIONS)->count())->toBe(2);
});
