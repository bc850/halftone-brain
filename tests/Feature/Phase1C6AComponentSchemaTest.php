<?php

use App\Enums\ItemKind;
use App\Enums\UnitOfMeasure;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\OrganizationProductComponent;
use App\Models\ParentAccount;
use App\Models\Product;
use App\Support\Catalog\ComponentCost\ComponentConversionInput;
use App\Support\Catalog\ComponentCost\ComponentCostEstimateInput;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Catalog\ComponentCost\ComponentLineInput;
use App\Support\Catalog\ComponentCost\ComponentsVersionContract;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

const PHASE_1C6A_MIGRATIONS = [
    '2026_07_25_210409_add_purchase_cost_and_components_version_to_organization_products_table',
    '2026_07_25_210445_create_organization_product_components_table',
];

function phase1c6aHasIndex(string $table, string $indexName, bool $unique = false): bool
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

function phase1c6aHasForeign(string $table, string $name, array $columns, string $foreignTable): bool
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

function phase1c6aRollback(): void
{
    Artisan::call('migrate:rollback', ['--step' => 6, '--force' => true]);
}

function phase1c6aRemigrate(): void
{
    Artisan::call('migrate', ['--force' => true]);
}

/**
 * @return array{parent: ParentAccount, organization: Organization, finished: OrganizationProduct, material: OrganizationProduct}
 */
function phase1c6aSeedGraph(): array
{
    $parent = ParentAccount::factory()->create();
    $organization = Organization::factory()->create([
        'parent_account_id' => $parent->id,
    ]);
    $finishedProduct = Product::factory()->create([
        'parent_account_id' => $parent->id,
        'item_kind' => ItemKind::Product,
        'sku' => 'FIN-'.uniqid(),
    ]);
    $materialProduct = Product::factory()->material()->create([
        'parent_account_id' => $parent->id,
        'sku' => 'MAT-'.uniqid(),
    ]);
    $finished = OrganizationProduct::factory()->create([
        'parent_account_id' => $parent->id,
        'organization_id' => $organization->id,
        'product_id' => $finishedProduct->id,
        'is_sellable' => true,
    ]);
    $material = OrganizationProduct::factory()->create([
        'parent_account_id' => $parent->id,
        'organization_id' => $organization->id,
        'product_id' => $materialProduct->id,
        'is_purchasable' => true,
        'purchase_unit_of_measure' => UnitOfMeasure::Sheet,
        'purchase_cost_micro_units' => Money::dollarsToMicroUnits('80'),
    ]);

    return compact('parent', 'organization', 'finished', 'material');
}

test('phase 1c6a organization product costing columns and defaults exist', function () {
    expect(Schema::hasColumns('organization_products', [
        'purchase_cost_micro_units',
        'components_version',
    ]))->toBeTrue();

    $op = OrganizationProduct::factory()->create();

    expect($op->purchase_cost_micro_units)->toBeNull()
        ->and($op->components_version)->toBe(ComponentsVersionContract::INITIAL_VERSION)
        ->and($op->material_cost_micro_units)->toBe(0);
});

test('phase 1c6a existing organization products receive components_version one', function () {
    phase1c6aRollback();

    expect(Schema::hasColumn('organization_products', 'components_version'))->toBeFalse();

    $parent = ParentAccount::factory()->create();
    $organization = Organization::factory()->create([
        'parent_account_id' => $parent->id,
    ]);
    $productId = Product::factory()->create([
        'parent_account_id' => $parent->id,
        'sku' => 'PRE-1C6A-'.uniqid(),
    ])->id;

    $opId = DB::table('organization_products')->insertGetId([
        'parent_account_id' => $parent->id,
        'organization_id' => $organization->id,
        'product_id' => $productId,
        'display_name' => null,
        'is_available' => true,
        'is_sellable' => true,
        'is_purchasable' => false,
        'inventory_tracking_mode' => 'none',
        'purchase_unit_of_measure' => null,
        'stock_unit_of_measure' => null,
        'usage_unit_of_measure' => null,
        'lead_time_days' => null,
        'notes' => null,
        'material_cost_micro_units' => 0,
        'labor_cost_micro_units' => 0,
        'overhead_mode' => 'none',
        'overhead_amount_micro_units' => 0,
        'overhead_rate_basis_points' => 0,
        'pricing_method' => 'markup',
        'markup_basis_points' => 0,
        'target_margin_basis_points' => 0,
        'fixed_price_cents' => null,
        'minimum_price_cents' => null,
        'allow_price_override' => false,
        'currency_code' => 'USD',
        'pricing_version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    phase1c6aRemigrate();

    expect((int) DB::table('organization_products')->where('id', $opId)->value('components_version'))
        ->toBe(1)
        ->and(DB::table('organization_products')->where('id', $opId)->value('purchase_cost_micro_units'))
        ->toBeNull();
});

test('phase 1c6a component table schema indexes and foreign keys', function () {
    expect(Schema::hasTable('organization_product_components'))->toBeTrue()
        ->and(Schema::hasColumns('organization_product_components', [
            'id',
            'parent_account_id',
            'organization_id',
            'organization_product_id',
            'component_organization_product_id',
            'quantity_scaled',
            'usage_uom',
            'waste_basis_points',
            'sort_order',
            'is_active',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(phase1c6aHasIndex('organization_product_components', 'opc_parent_component_uidx', unique: true))->toBeTrue()
        ->and(phase1c6aHasIndex('organization_product_components', 'opc_parent_active_sort_idx'))->toBeTrue()
        ->and(phase1c6aHasIndex('organization_product_components', 'opc_component_active_idx'))->toBeTrue()
        ->and(phase1c6aHasForeign(
            'organization_product_components',
            'opc_pa_org_fk',
            ['parent_account_id', 'organization_id'],
            'organizations',
        ))->toBeTrue()
        ->and(phase1c6aHasForeign(
            'organization_product_components',
            'opc_org_parent_fk',
            ['organization_id', 'organization_product_id'],
            'organization_products',
        ))->toBeTrue()
        ->and(phase1c6aHasForeign(
            'organization_product_components',
            'opc_org_component_fk',
            ['organization_id', 'component_organization_product_id'],
            'organization_products',
        ))->toBeTrue();
});

test('phase 1c6a component defaults and quantity scale persist', function () {
    $g = phase1c6aSeedGraph();

    $component = OrganizationProductComponent::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'organization_product_id' => $g['finished']->id,
        'component_organization_product_id' => $g['material']->id,
        'quantity_scaled' => ComponentCostEstimator::quantityToScaled('10.123456'),
        'usage_uom' => UnitOfMeasure::SquareFoot,
    ]);

    expect($component->waste_basis_points)->toBe(0)
        ->and($component->sort_order)->toBe(0)
        ->and($component->is_active)->toBeTrue()
        ->and($component->quantity_scaled)->toBe(10_123_456)
        ->and($component->usage_uom)->toBe(UnitOfMeasure::SquareFoot);
});

test('phase 1c6a rejects duplicate parent component pairs', function () {
    $g = phase1c6aSeedGraph();

    OrganizationProductComponent::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'organization_product_id' => $g['finished']->id,
        'component_organization_product_id' => $g['material']->id,
    ]);

    expect(fn () => OrganizationProductComponent::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'organization_product_id' => $g['finished']->id,
        'component_organization_product_id' => $g['material']->id,
    ]))->toThrow(QueryException::class);
});

test('phase 1c6a rejects self reference and zero quantity at model layer', function () {
    $g = phase1c6aSeedGraph();

    expect(fn () => OrganizationProductComponent::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'organization_product_id' => $g['finished']->id,
        'component_organization_product_id' => $g['finished']->id,
    ]))->toThrow(ValidationException::class);

    expect(fn () => OrganizationProductComponent::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'organization_product_id' => $g['finished']->id,
        'component_organization_product_id' => $g['material']->id,
        'quantity_scaled' => 0,
    ]))->toThrow(ValidationException::class);
});

test('phase 1c6a rejects cross organization component rows', function () {
    $g = phase1c6aSeedGraph();
    $otherOrg = Organization::factory()->create([
        'parent_account_id' => $g['parent']->id,
    ]);
    $otherMaterialProduct = Product::factory()->material()->create([
        'parent_account_id' => $g['parent']->id,
        'sku' => 'MAT-OTHER-'.uniqid(),
    ]);
    $otherMaterial = OrganizationProduct::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $otherOrg->id,
        'product_id' => $otherMaterialProduct->id,
        'is_purchasable' => true,
        'purchase_unit_of_measure' => UnitOfMeasure::Sheet,
        'purchase_cost_micro_units' => Money::dollarsToMicroUnits('80'),
    ]);

    expect(fn () => OrganizationProductComponent::query()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'organization_product_id' => $g['finished']->id,
        'component_organization_product_id' => $otherMaterial->id,
        'quantity_scaled' => ComponentCostEstimator::QUANTITY_SCALE_FACTOR,
        'usage_uom' => UnitOfMeasure::SquareFoot->value,
        'waste_basis_points' => 0,
        'sort_order' => 0,
        'is_active' => true,
    ]))->toThrow(ValidationException::class);
});

test('phase 1c6a restrict deletes prevent removing used products', function () {
    $g = phase1c6aSeedGraph();

    OrganizationProductComponent::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'organization_product_id' => $g['finished']->id,
        'component_organization_product_id' => $g['material']->id,
    ]);

    expect(fn () => $g['finished']->delete())->toThrow(QueryException::class)
        ->and(fn () => $g['material']->delete())->toThrow(QueryException::class);
});

test('phase 1c6a relationships load parent and component products', function () {
    $g = phase1c6aSeedGraph();

    $component = OrganizationProductComponent::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'organization_product_id' => $g['finished']->id,
        'component_organization_product_id' => $g['material']->id,
    ]);

    expect($g['finished']->fresh()->components)->toHaveCount(1)
        ->and($g['material']->fresh()->componentUsages)->toHaveCount(1)
        ->and($component->organizationProduct->is($g['finished']))->toBeTrue()
        ->and($component->componentOrganizationProduct->is($g['material']))->toBeTrue();
});

test('phase 1c6a rollback removes component schema before costing columns', function () {
    $g = phase1c6aSeedGraph();

    OrganizationProductComponent::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['organization']->id,
        'organization_product_id' => $g['finished']->id,
        'component_organization_product_id' => $g['material']->id,
    ]);

    expect(Schema::hasTable('organization_product_components'))->toBeTrue()
        ->and(Schema::hasColumn('organization_products', 'purchase_cost_micro_units'))->toBeTrue()
        ->and(Schema::hasTable('organization_product_unit_conversions'))->toBeTrue();

    phase1c6aRollback();

    expect(Schema::hasTable('organization_product_components'))->toBeFalse()
        ->and(Schema::hasColumn('organization_products', 'purchase_cost_micro_units'))->toBeFalse()
        ->and(Schema::hasColumn('organization_products', 'components_version'))->toBeFalse()
        ->and(Schema::hasTable('organization_product_unit_conversions'))->toBeTrue()
        ->and(Schema::hasColumn('organization_products', 'is_sellable'))->toBeTrue()
        ->and(Schema::hasColumn('products', 'item_kind'))->toBeTrue()
        ->and(DB::table('organization_products')->where('id', $g['finished']->id)->exists())->toBeTrue();

    phase1c6aRemigrate();

    expect(Schema::hasTable('organization_product_components'))->toBeTrue()
        ->and(Schema::hasColumn('organization_products', 'purchase_cost_micro_units'))->toBeTrue()
        ->and(Schema::hasColumn('organization_products', 'components_version'))->toBeTrue()
        ->and((int) DB::table('organization_products')->where('id', $g['finished']->id)->value('components_version'))
        ->toBe(1);
});

test('phase 1c6a pure estimate issues no database queries', function () {
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $estimate = (new ComponentCostEstimator)->estimate(
        new ComponentCostEstimateInput(
            organizationProductId: 1,
            parentAccountId: 1,
            organizationId: 1,
            itemKind: ItemKind::Product,
            isSellable: true,
            components: [
                new ComponentLineInput(
                    componentOrganizationProductId: 2,
                    parentAccountId: 1,
                    organizationId: 1,
                    itemKind: ItemKind::Material,
                    isPurchasable: true,
                    purchaseUnitOfMeasure: UnitOfMeasure::Sheet,
                    purchaseCostMicroUnits: Money::dollarsToMicroUnits('80'),
                    quantityScaled: ComponentCostEstimator::quantityToScaled('10'),
                    usageUnitOfMeasure: UnitOfMeasure::SquareFoot,
                    wasteBasisPoints: 1000,
                    conversions: [
                        new ComponentConversionInput(
                            UnitOfMeasure::Sheet,
                            UnitOfMeasure::SquareFoot,
                            32,
                            1,
                            true,
                        ),
                    ],
                ),
            ],
        ),
    );

    expect($queries)->toBe(0)
        ->and($estimate->totalEstimatedMaterialCostMicroUnits)
        ->toBe(Money::dollarsToMicroUnits('27.50'))
        ->and(DB::table('audit_events')->count())->toBeGreaterThanOrEqual(0);
});
