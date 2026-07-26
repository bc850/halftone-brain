<?php

use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\OrganizationCompany;
use App\Models\ParentAccount;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Team;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Tenancy\TenantSchemaHardeningGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function phase0eColumnIsNullable(string $table, string $column): bool
{
    $driver = Schema::getConnection()->getDriverName();

    if ($driver === 'sqlite') {
        $info = collect(DB::select("pragma table_info({$table})"))->firstWhere('name', $column);

        return (int) ($info->notnull ?? 1) === 0;
    }

    $row = DB::selectOne(
        'select IS_NULLABLE as is_nullable from information_schema.COLUMNS where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?',
        [$table, $column]
    );

    return ($row->is_nullable ?? 'YES') === 'YES';
}

function phase0eHasIndex(string $table, string $indexName, bool $unique = false): bool
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

function phase0eHasForeign(string $table, string $name, array $columns, string $foreignTable): bool
{
    $driver = Schema::getConnection()->getDriverName();

    foreach (Schema::getForeignKeys($table) as $foreign) {
        $cols = $foreign['columns'] ?? [];
        $refTable = $foreign['foreign_table'] ?? null;

        if ($cols !== $columns || $refTable !== $foreignTable) {
            continue;
        }

        if ($driver === 'mysql') {
            return ($foreign['name'] ?? null) === $name;
        }

        // SQLite does not persist custom foreign-key names.
        return true;
    }

    return false;
}

function phase0eSeedValidGraph(): array
{
    $parent = ParentAccount::factory()->create();
    $otherParent = ParentAccount::factory()->create();
    $org = Organization::factory()->create(['parent_account_id' => $parent->id]);
    $otherOrg = Organization::factory()->create(['parent_account_id' => $otherParent->id]);
    $user = User::factory()->create();

    $company = Company::factory()->create([
        'parent_account_id' => $parent->id,
        'owner_id' => $user->id,
    ]);
    $foreignCompany = Company::factory()->create([
        'parent_account_id' => $otherParent->id,
        'owner_id' => $user->id,
    ]);

    $orgCompany = OrganizationCompany::factory()->create([
        'organization_id' => $org->id,
        'company_id' => $company->id,
        'parent_account_id' => $parent->id,
    ]);

    $vendor = Vendor::factory()->create(['parent_account_id' => $parent->id]);
    $category = ProductCategory::factory()->create(['parent_account_id' => $parent->id]);
    $foreignVendor = Vendor::factory()->create(['parent_account_id' => $otherParent->id]);
    $foreignCategory = ProductCategory::factory()->create(['parent_account_id' => $otherParent->id]);

    return compact(
        'parent',
        'otherParent',
        'org',
        'otherOrg',
        'user',
        'company',
        'foreignCompany',
        'orgCompany',
        'vendor',
        'category',
        'foreignVendor',
        'foreignCategory',
    );
}

test('phase 0e required tenant columns are not null', function () {
    $columns = [
        ['companies', 'parent_account_id'],
        ['contacts', 'parent_account_id'],
        ['vendors', 'parent_account_id'],
        ['product_categories', 'parent_account_id'],
        ['products', 'parent_account_id'],
        ['deals', 'parent_account_id'],
        ['deals', 'organization_id'],
        ['deals', 'organization_company_id'],
        ['teams', 'parent_account_id'],
        ['teams', 'organization_id'],
    ];

    foreach ($columns as [$table, $column]) {
        expect(phase0eColumnIsNullable($table, $column))->toBeFalse("{$table}.{$column}");
    }
});

test('phase 0e approved unique indexes and composite foreign keys exist with short names', function () {
    expect(phase0eHasIndex('companies', 'co_pa_id_uidx', unique: true))->toBeTrue()
        ->and(phase0eHasIndex('products', 'pr_pa_id_uidx', unique: true))->toBeTrue()
        ->and(phase0eHasIndex('vendors', 've_pa_id_uidx', unique: true))->toBeTrue()
        ->and(phase0eHasIndex('product_categories', 'pc_pa_id_uidx', unique: true))->toBeTrue();

    $foreigns = [
        ['organization_companies', 'oc_pa_org_fk', ['parent_account_id', 'organization_id'], 'organizations'],
        ['organization_companies', 'oc_pa_co_fk', ['parent_account_id', 'company_id'], 'companies'],
        ['contacts', 'ct_pa_co_fk', ['parent_account_id', 'company_id'], 'companies'],
        ['products', 'pr_pa_pc_fk', ['parent_account_id', 'product_category_id'], 'product_categories'],
        ['deals', 'de_org_oc_fk', ['organization_id', 'organization_company_id'], 'organization_companies'],
        ['deals', 'de_pa_org_fk', ['parent_account_id', 'organization_id'], 'organizations'],
        ['teams', 'tm_pa_org_fk', ['parent_account_id', 'organization_id'], 'organizations'],
        ['audit_events', 'au_pa_org_fk', ['parent_account_id', 'organization_id'], 'organizations'],
    ];

    foreach ($foreigns as [$table, $name, $columns, $foreignTable]) {
        expect(strlen($name))->toBeLessThan(64)
            ->and(phase0eHasForeign($table, $name, $columns, $foreignTable))->toBeTrue($name);
    }
});

test('phase 0e accepts valid same-tenant inserts and optional null relationships', function () {
    $g = phase0eSeedValidGraph();

    $contactId = DB::table('contacts')->insertGetId([
        'parent_account_id' => $g['parent']->id,
        'company_id' => $g['company']->id,
        'first_name' => 'Valid',
        'last_name' => 'Contact',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $productId = DB::table('products')->insertGetId([
        'parent_account_id' => $g['parent']->id,
        'name' => 'Valid Product',
        'sku' => 'VALID-SKU-1',
        'unit_of_measure' => 'each',
        'true_cost_micro_units' => 1000,
        'markup_basis_points' => 1000,
        'list_price_cents' => 110,
        'product_category_id' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('products')->where('id', $productId)->update([
        'product_category_id' => $g['category']->id,
    ]);

    $dealId = DB::table('deals')->insertGetId([
        'organization_id' => $g['org']->id,
        'parent_account_id' => $g['parent']->id,
        'company_id' => $g['company']->id,
        'organization_company_id' => $g['orgCompany']->id,
        'owner_id' => $g['user']->id,
        'name' => 'Valid Deal',
        'stage' => 'lead',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $teamId = DB::table('teams')->insertGetId([
        'organization_id' => $g['org']->id,
        'parent_account_id' => $g['parent']->id,
        'name' => 'Valid Team',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $auditId = DB::table('audit_events')->insertGetId([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => null,
        'actor_user_id' => null,
        'action' => 'test.event',
        'subject_type' => Company::class,
        'subject_id' => $g['company']->id,
        'created_at' => now(),
    ]);

    expect(Contact::query()->whereKey($contactId)->exists())->toBeTrue()
        ->and(Product::query()->whereKey($productId)->exists())->toBeTrue()
        ->and(Deal::query()->whereKey($dealId)->exists())->toBeTrue()
        ->and(Team::query()->whereKey($teamId)->exists())->toBeTrue()
        ->and(AuditEvent::query()->whereKey($auditId)->value('organization_id'))->toBeNull();
});

test('phase 0e rejects null required tenant ids and cross-tenant composites', function () {
    $g = phase0eSeedValidGraph();

    expect(fn () => DB::table('companies')->insert([
        'parent_account_id' => null,
        'name' => 'Null Parent Co',
        'owner_id' => $g['user']->id,
        'sales_tax_status' => 'taxable',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('organization_companies')->insert([
        'organization_id' => $g['org']->id,
        'company_id' => $g['foreignCompany']->id,
        'parent_account_id' => $g['parent']->id,
        'lifecycle_status' => 'prospect',
        'relationship_status' => 'new',
        'tax_posture' => 'unknown',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('contacts')->insert([
        'parent_account_id' => $g['parent']->id,
        'company_id' => $g['foreignCompany']->id,
        'first_name' => 'Bad',
        'last_name' => 'Contact',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('products')->insert([
        'parent_account_id' => $g['parent']->id,
        'name' => 'Bad Product Cat',
        'sku' => 'BAD-SKU-2',
        'unit_of_measure' => 'each',
        'true_cost_micro_units' => 1000,
        'markup_basis_points' => 1000,
        'list_price_cents' => 110,
        'product_category_id' => $g['foreignCategory']->id,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    $otherOrgCompany = OrganizationCompany::factory()->create([
        'organization_id' => $g['otherOrg']->id,
        'company_id' => $g['foreignCompany']->id,
        'parent_account_id' => $g['otherParent']->id,
    ]);

    expect(fn () => DB::table('deals')->insert([
        'organization_id' => $g['org']->id,
        'parent_account_id' => $g['parent']->id,
        'company_id' => $g['company']->id,
        'organization_company_id' => $otherOrgCompany->id,
        'owner_id' => $g['user']->id,
        'name' => 'Bad OC Deal',
        'stage' => 'lead',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('deals')->insert([
        'organization_id' => $g['org']->id,
        'parent_account_id' => $g['otherParent']->id,
        'company_id' => $g['company']->id,
        'organization_company_id' => $g['orgCompany']->id,
        'owner_id' => $g['user']->id,
        'name' => 'Bad Parent Deal',
        'stage' => 'lead',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('teams')->insert([
        'organization_id' => $g['org']->id,
        'parent_account_id' => $g['otherParent']->id,
        'name' => 'Bad Team',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('audit_events')->insert([
        'parent_account_id' => $g['parent']->id,
        'organization_id' => $g['otherOrg']->id,
        'action' => 'bad.audit',
        'subject_type' => Company::class,
        'subject_id' => $g['company']->id,
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('phase 0e safety gate reports violations without repairing', function () {
    expect(TenantSchemaHardeningGuard::violations())->toBe([]);

    // Guard is read-only; empty DB / valid fixtures produce no violations.
    $g = phase0eSeedValidGraph();
    Contact::factory()->create([
        'parent_account_id' => $g['parent']->id,
        'company_id' => $g['company']->id,
    ]);

    expect(TenantSchemaHardeningGuard::violations())->toBe([]);
});

test('phase 0e migrations roll back and remigrate cleanly', function () {
    $beforeCompaniesNullable = phase0eColumnIsNullable('companies', 'parent_account_id');
    expect($beforeCompaniesNullable)->toBeFalse();

    // Phase 1C.7D (1) + Phase 1C.7A (4) + Phase 1C.6A (2) + Phase 1C.4 (3) + Phase 1A (2) + 0F drop + three 0E hardening migrations.
    Artisan::call('migrate:rollback', ['--step' => 20, '--force' => true]);

    expect(phase0eColumnIsNullable('companies', 'parent_account_id'))->toBeTrue()
        ->and(phase0eHasForeign('organization_companies', 'oc_pa_org_fk', ['parent_account_id', 'organization_id'], 'organizations'))->toBeFalse()
        ->and(phase0eHasIndex('companies', 'co_pa_id_uidx', unique: true))->toBeFalse()
        ->and(Schema::hasTable('parent_accounts'))->toBeTrue()
        ->and(Schema::hasTable('organization_companies'))->toBeTrue();

    Artisan::call('migrate', ['--force' => true]);

    expect(phase0eColumnIsNullable('companies', 'parent_account_id'))->toBeFalse()
        ->and(phase0eHasForeign('organization_companies', 'oc_pa_org_fk', ['parent_account_id', 'organization_id'], 'organizations'))->toBeTrue()
        ->and(phase0eHasIndex('companies', 'co_pa_id_uidx', unique: true))->toBeTrue();
});
