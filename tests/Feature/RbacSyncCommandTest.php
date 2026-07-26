<?php

use App\Enums\RoleScope;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\ParentAccountMembership;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\PhaseZeroBootstrap;
use App\Support\Tenancy\RbacDefinitions;
use App\Support\Tenancy\RbacSynchronizer;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;

function rbacSyncDbName(): string
{
    $connection = config('database.default');

    return (string) config("database.connections.{$connection}.database");
}

function runRbacSync(array $options = [], bool $execute = false): PendingCommand
{
    $parameters = [
        '--confirm-database' => $options['confirm'] ?? rbacSyncDbName(),
    ];

    if ($execute) {
        $parameters['--execute'] = true;
    }

    return test()->artisan('rbac:sync', $parameters);
}

/**
 * Seed system RBAC as it existed before the six OrganizationProduct permissions.
 */
function seedPreOrgProductRbac(): void
{
    $excluded = preOrgProductExcludedKeys();

    foreach (RbacDefinitions::permissions() as $permission) {
        if (in_array($permission['key'], $excluded, true)) {
            continue;
        }

        Permission::query()->create([
            'key' => $permission['key'],
            'module' => $permission['module'],
            'description' => $permission['description'],
        ]);
    }

    foreach (RbacDefinitions::systemRoles() as $key => $roleDefinition) {
        $role = Role::query()->create([
            'key' => $key,
            'name' => $roleDefinition['name'],
            'scope' => RoleScope::System,
            'parent_account_id' => null,
            'organization_id' => null,
        ]);

        $permissionKeys = array_values(array_filter(
            $roleDefinition['permissions'],
            fn (string $permissionKey): bool => ! in_array($permissionKey, $excluded, true),
        ));

        $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $role->permissions()->sync($permissionIds);
    }
}

/**
 * @return list<string>
 */
function preOrgProductExcludedKeys(): array
{
    return [
        'catalog.org_product.manage',
        'catalog.org_product.manage_pricing',
        'catalog.org_product.override_price',
        'catalog.org_product.override_margin',
        'catalog.org_product.approve_below_minimum',
        'catalog.org_product.archive',
    ];
}

function expectedPreOrgProductPermissionCount(): int
{
    return count(array_filter(
        RbacDefinitions::permissions(),
        fn (array $permission): bool => ! in_array($permission['key'], preOrgProductExcludedKeys(), true),
    ));
}

function expectedPreOrgProductRolePermissionCount(): int
{
    $excluded = preOrgProductExcludedKeys();

    return (int) collect(RbacDefinitions::systemRoles())->sum(
        fn (array $role): int => count(array_filter(
            $role['permissions'],
            fn (string $key): bool => ! in_array($key, $excluded, true),
        )),
    );
}

function nonRbacCounts(): array
{
    return [
        'parent_accounts' => ParentAccount::query()->count(),
        'organizations' => Organization::query()->count(),
        'memberships' => Membership::query()->count(),
        'parent_memberships' => ParentAccountMembership::query()->count(),
        'users' => User::query()->count(),
        'products' => Product::query()->count(),
        'audits' => AuditEvent::query()->count(),
        'membership_role' => DB::table('membership_role')->count(),
        'parent_account_membership_role' => DB::table('parent_account_membership_role')->count(),
    ];
}

test('dry run performs no writes and reports exact org product delta', function () {
    seedPreOrgProductRbac();

    $excludedOrgProductKeys = [
        'catalog.org_product.manage',
        'catalog.org_product.manage_pricing',
        'catalog.org_product.override_price',
        'catalog.org_product.override_margin',
        'catalog.org_product.approve_below_minimum',
        'catalog.org_product.archive',
    ];

    $expectedPermissionCount = count(array_filter(
        RbacDefinitions::permissions(),
        fn (array $permission): bool => ! in_array($permission['key'], $excludedOrgProductKeys, true),
    ));

    $expectedRolePermissionCount = collect(RbacDefinitions::systemRoles())->sum(
        fn (array $role): int => count(array_filter(
            $role['permissions'],
            fn (string $key): bool => ! in_array($key, $excludedOrgProductKeys, true),
        )),
    );

    expect(Permission::query()->count())->toBe($expectedPermissionCount)
        ->and(Role::query()->count())->toBe(10)
        ->and(DB::table('role_permission')->count())->toBe($expectedRolePermissionCount);

    $before = nonRbacCounts();
    $plan = app(RbacSynchronizer::class)->buildPlan();

    $permissionKeys = collect($plan['permissions_to_create'])->pluck('key')->sort()->values()->all();
    expect($permissionKeys)->toBe([
        'catalog.org_product.approve_below_minimum',
        'catalog.org_product.archive',
        'catalog.org_product.manage',
        'catalog.org_product.manage_pricing',
        'catalog.org_product.override_margin',
        'catalog.org_product.override_price',
    ])
        ->and($plan['roles_to_create'])->toBe([])
        ->and($plan['permissions_to_update'])->toBe([])
        ->and($plan['roles_to_update'])->toBe([])
        ->and($plan['conflicts'])->toBe([])
        ->and($plan['grants_to_add'])->toHaveCount(16);

    $grantsByRole = collect($plan['grants_to_add'])->groupBy('role')->map->pluck('permission')->map->sort()->map->values();

    expect($grantsByRole->keys()->sort()->values()->all())->toBe(['admin', 'finance', 'owner', 'sales_manager'])
        ->and($grantsByRole['owner']->all())->toBe($permissionKeys)
        ->and($grantsByRole['admin']->all())->toBe($permissionKeys)
        ->and($grantsByRole['sales_manager']->all())->toBe([
            'catalog.org_product.approve_below_minimum',
            'catalog.org_product.override_margin',
            'catalog.org_product.override_price',
        ])
        ->and($grantsByRole['finance']->all())->toBe([
            'catalog.org_product.manage_pricing',
        ]);

    runRbacSync()
        ->assertSuccessful()
        ->expectsOutputToContain('Permissions to create (6):')
        ->expectsOutputToContain('Role-permission grants to add (16):')
        ->expectsOutputToContain('No writes were performed');

    expect(Permission::query()->count())->toBe(expectedPreOrgProductPermissionCount())
        ->and(DB::table('role_permission')->count())->toBe(expectedPreOrgProductRolePermissionCount())
        ->and(nonRbacCounts())->toBe($before);
});

test('exact database confirmation is required and mismatch refuses before writes', function () {
    seedPreOrgProductRbac();

    test()->artisan('rbac:sync', [
        '--execute' => true,
    ])->assertFailed();

    test()->artisan('rbac:sync', [
        '--execute' => true,
        '--confirm-database' => 'wrong-database-name',
    ])->assertFailed()
        ->expectsOutputToContain('does not match active database');

    expect(Permission::query()->count())->toBe(expectedPreOrgProductPermissionCount())
        ->and(DB::table('role_permission')->count())->toBe(expectedPreOrgProductRolePermissionCount());
});

test('execute creates only missing RBAC definitions and grants', function () {
    seedPreOrgProductRbac();
    $before = nonRbacCounts();

    runRbacSync(execute: true)
        ->assertSuccessful()
        ->expectsOutputToContain('created');

    expect(Permission::query()->count())->toBe(count(RbacDefinitions::permissions()))
        ->and(Role::query()->count())->toBe(10)
        ->and(DB::table('role_permission')->count())->toBe(collect(RbacDefinitions::systemRoles())->sum(fn (array $role): int => count($role['permissions'])))
        ->and(nonRbacCounts())->toBe($before);

    $newKeys = [
        'catalog.org_product.manage',
        'catalog.org_product.manage_pricing',
        'catalog.org_product.override_price',
        'catalog.org_product.override_margin',
        'catalog.org_product.approve_below_minimum',
        'catalog.org_product.archive',
    ];

    foreach ($newKeys as $key) {
        expect(Permission::query()->where('key', $key)->count())->toBe(1);
    }

    $owner = Role::query()->where('key', 'owner')->firstOrFail();
    $admin = Role::query()->where('key', 'admin')->firstOrFail();
    $salesManager = Role::query()->where('key', 'sales_manager')->firstOrFail();
    $finance = Role::query()->where('key', 'finance')->firstOrFail();
    $salesperson = Role::query()->where('key', 'salesperson')->firstOrFail();

    foreach ($newKeys as $key) {
        expect($owner->permissions()->where('key', $key)->exists())->toBeTrue()
            ->and($admin->permissions()->where('key', $key)->exists())->toBeTrue()
            ->and($salesperson->permissions()->where('key', $key)->exists())->toBeFalse();
    }

    expect($salesManager->permissions()->whereIn('key', [
        'catalog.org_product.override_price',
        'catalog.org_product.override_margin',
        'catalog.org_product.approve_below_minimum',
    ])->count())->toBe(3)
        ->and($salesManager->permissions()->where('key', 'catalog.org_product.manage_pricing')->exists())->toBeFalse()
        ->and($finance->permissions()->where('key', 'catalog.org_product.manage_pricing')->exists())->toBeTrue()
        ->and($finance->permissions()->where('key', 'catalog.org_product.approve_below_minimum')->exists())->toBeFalse();

    foreach (['parent_owner', 'parent_admin', 'parent_catalog_manager'] as $parentRoleKey) {
        $role = Role::query()->where('key', $parentRoleKey)->firstOrFail();
        expect($role->permissions()->where('key', 'like', 'catalog.org_product.%')->count())->toBe(0);
    }
});

test('second dry run and execute are idempotent', function () {
    seedPreOrgProductRbac();
    runRbacSync(execute: true)->assertSuccessful();

    $before = [
        'permissions' => Permission::query()->count(),
        'roles' => Role::query()->count(),
        'role_permission' => DB::table('role_permission')->count(),
        ...nonRbacCounts(),
    ];

    runRbacSync()
        ->assertSuccessful()
        ->expectsOutputToContain('Permissions to create (0):')
        ->expectsOutputToContain('Role-permission grants to add (0):')
        ->expectsOutputToContain('No RBAC changes proposed');

    runRbacSync(execute: true)->assertSuccessful();

    expect(Permission::query()->count())->toBe($before['permissions'])
        ->and(Role::query()->count())->toBe($before['roles'])
        ->and(DB::table('role_permission')->count())->toBe($before['role_permission'])
        ->and(nonRbacCounts())->toMatchArray(collect($before)->only([
            'parent_accounts',
            'organizations',
            'memberships',
            'parent_memberships',
            'users',
            'products',
            'audits',
            'membership_role',
            'parent_account_membership_role',
        ])->all());
});

test('unrelated custom grants are preserved and never detached', function () {
    seedPreOrgProductRbac();

    $custom = Permission::query()->create([
        'key' => 'custom.experimental.flag',
        'module' => 'custom',
        'description' => 'Custom grant that must survive sync',
    ]);

    $owner = Role::query()->where('key', 'owner')->firstOrFail();
    $owner->permissions()->attach($custom->id);

    $grantCountBefore = DB::table('role_permission')->count();

    runRbacSync(execute: true)->assertSuccessful();

    expect($owner->fresh()->permissions()->where('key', 'custom.experimental.flag')->exists())->toBeTrue()
        ->and(DB::table('role_permission')->count())->toBe($grantCountBefore + 16)
        ->and(Permission::query()->where('key', 'custom.experimental.flag')->exists())->toBeTrue();
});

test('permission metadata updates are reported and applied without deleting', function () {
    seedPreOrgProductRbac();

    Permission::query()->where('key', 'crm.company.view')->update([
        'description' => 'Stale description',
    ]);

    runRbacSync()
        ->assertSuccessful()
        ->expectsOutputToContain('Permissions to update (1):')
        ->expectsOutputToContain('crm.company.view');

    runRbacSync(execute: true)->assertSuccessful();

    $permission = Permission::query()->where('key', 'crm.company.view')->firstOrFail();
    $definition = collect(RbacDefinitions::permissions())->firstWhere('key', 'crm.company.view');

    expect($permission->description)->toBe($definition['description'])
        ->and(Permission::query()->where('key', 'crm.company.view')->count())->toBe(1);
});

test('conflicting non-system role blocks execute', function () {
    seedPreOrgProductRbac();

    Role::query()->where('key', 'owner')->update([
        'scope' => RoleScope::Organization,
    ]);

    runRbacSync()
        ->assertFailed()
        ->expectsOutputToContain('Conflicts (1):')
        ->expectsOutputToContain('owner');

    runRbacSync(execute: true)->assertFailed();

    expect(Permission::query()->where('key', 'like', 'catalog.org_product.%')->count())->toBe(0);
});

test('induced failure rolls back all RBAC writes and releases lock', function () {
    seedPreOrgProductRbac();
    app()->instance('rbacSync.induceFailure', true);

    runRbacSync(execute: true)->assertFailed();

    expect(Permission::query()->count())->toBe(expectedPreOrgProductPermissionCount())
        ->and(DB::table('role_permission')->count())->toBe(expectedPreOrgProductRolePermissionCount())
        ->and(app(RbacSynchronizer::class)->isLockFree())->toBeTrue();

    app()->instance('rbacSync.induceFailure', false);

    runRbacSync(execute: true)->assertSuccessful();

    expect(Permission::query()->count())->toBe(count(RbacDefinitions::permissions()))
        ->and(app(RbacSynchronizer::class)->isLockFree())->toBeTrue();
});

test('command does not invoke phase zero tenant bootstrap completion audits', function () {
    seedPreOrgProductRbac();

    runRbacSync(execute: true)->assertSuccessful();

    expect(AuditEvent::query()->where('action', PhaseZeroBootstrap::COMPLETION_ACTION)->count())->toBe(0)
        ->and(ParentAccount::query()->count())->toBe(0)
        ->and(Organization::query()->count())->toBe(0);
});

test('full rbac seeder baseline has zero sync delta', function () {
    (new RbacSeeder)->run();

    runRbacSync()
        ->assertSuccessful()
        ->expectsOutputToContain('Permissions to create (0):')
        ->expectsOutputToContain('Role-permission grants to add (0):')
        ->expectsOutputToContain('No RBAC changes proposed');
});
