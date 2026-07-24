<?php

use App\Models\User;
use App\Support\Tenancy\TenantSchemaHardeningGuard;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const PHASE_0E_MIGRATIONS = [
    '2026_07_24_011759_add_tenant_composite_unique_indexes',
    '2026_07_24_011800_add_tenant_composite_foreign_keys',
    '2026_07_24_011801_make_tenant_ownership_columns_not_null',
];

/**
 * @return list<string>
 */
function phase0e3PendingMigrationNames(): array
{
    $ran = DB::table('migrations')->pluck('migration')->all();

    return array_values(array_filter(
        PHASE_0E_MIGRATIONS,
        fn (string $name): bool => ! in_array($name, $ran, true)
    ));
}

function phase0e3ColumnIsNullable(string $table, string $column): bool
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

function phase0e3HasIndex(string $table, string $indexName, bool $unique = false): bool
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

function phase0e3HasForeign(string $table, string $name, array $columns, string $foreignTable): bool
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

        return true;
    }

    return false;
}

function phase0e3AssertSoftSchema(): void
{
    expect(phase0e3PendingMigrationNames())->toBe(PHASE_0E_MIGRATIONS)
        ->and(phase0e3ColumnIsNullable('companies', 'parent_account_id'))->toBeTrue()
        ->and(phase0e3ColumnIsNullable('deals', 'organization_id'))->toBeTrue()
        ->and(phase0e3HasIndex('companies', 'co_pa_id_uidx', unique: true))->toBeFalse()
        ->and(phase0e3HasForeign(
            'organization_companies',
            'oc_pa_org_fk',
            ['parent_account_id', 'organization_id'],
            'organizations'
        ))->toBeFalse();
}

function phase0e3RollbackHardening(): void
{
    Artisan::call('migrate:rollback', ['--step' => 3, '--force' => true]);
    phase0e3AssertSoftSchema();
}

test('migrate pretend succeeds for pending 0e migrations without applying schema', function () {
    phase0e3RollbackHardening();

    $exitCode = Artisan::call('migrate', ['--pretend' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('2026_07_24_011759_add_tenant_composite_unique_indexes')
        ->and($output)->toContain('2026_07_24_011800_add_tenant_composite_foreign_keys')
        ->and($output)->toContain('2026_07_24_011801_make_tenant_ownership_columns_not_null')
        ->and($output)->toContain('co_pa_id_uidx')
        ->and($output)->toContain('pr_pa_id_uidx')
        ->and($output)->toContain('references "organizations"')
        ->and($output)->toContain('parent_account_id');

    phase0e3AssertSoftSchema();
});

test('real migration with intentional null tenant value fails closed without partial 0e schema', function () {
    phase0e3RollbackHardening();

    $userId = User::factory()->create()->id;

    DB::table('companies')->insert([
        'parent_account_id' => null,
        'name' => '0E.3 Null Probe',
        'owner_id' => $userId,
        'sales_tax_status' => 'taxable',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => Artisan::call('migrate', ['--force' => true]))
        ->toThrow(RuntimeException::class, 'null_tenant:companies.parent_account_id');

    phase0e3AssertSoftSchema();

    DB::table('companies')->where('name', '0E.3 Null Probe')->delete();
});

test('unexpected null validation query result outside pretend fails closed', function () {
    expect(DB::connection()->pretending())->toBeFalse();

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('pretending')->andReturn(false);

    DB::partialMock()
        ->shouldReceive('connection')
        ->andReturn($connection)
        ->shouldReceive('selectOne')
        ->andReturn(null);

    expect(fn () => TenantSchemaHardeningGuard::violations())
        ->toThrow(RuntimeException::class, 'validation query returned no result');

    expect(fn () => TenantSchemaHardeningGuard::assertReadyOrFail())
        ->toThrow(RuntimeException::class, 'validation query returned no result');
});

test('real valid migration after pretend still succeeds', function () {
    phase0e3RollbackHardening();

    expect(Artisan::call('migrate', ['--pretend' => true]))->toBe(0);
    phase0e3AssertSoftSchema();

    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0);

    expect(phase0e3PendingMigrationNames())->toBe([])
        ->and(phase0e3ColumnIsNullable('companies', 'parent_account_id'))->toBeFalse()
        ->and(phase0e3HasIndex('companies', 'co_pa_id_uidx', unique: true))->toBeTrue()
        ->and(phase0e3HasForeign(
            'organization_companies',
            'oc_pa_org_fk',
            ['parent_account_id', 'organization_id'],
            'organizations'
        ))->toBeTrue();
});

test('guard skips data validation while connection is pretending', function () {
    $queries = DB::connection()->pretend(function (): void {
        expect(DB::connection()->pretending())->toBeTrue()
            ->and(TenantSchemaHardeningGuard::violations())->toBe([]);

        TenantSchemaHardeningGuard::assertReadyOrFail();
    });

    expect($queries)->toBeArray();
});
