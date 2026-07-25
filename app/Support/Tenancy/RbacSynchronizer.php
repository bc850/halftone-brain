<?php

namespace App\Support\Tenancy;

use App\Enums\RoleScope;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Production-safe RBAC definition synchronization against {@see RbacDefinitions}.
 *
 * Never deletes permissions/roles and never detaches role-permission grants.
 * Never touches memberships, parent memberships, tenants, or business data.
 */
final class RbacSynchronizer
{
    public const LOCK_KEY = 'halftone_rbac_sync';

    /**
     * @return array{
     *     dry_run: bool,
     *     database: string,
     *     plan: array{
     *         permissions_to_create: list<array{key: string, module: string, description: string|null}>,
     *         permissions_to_update: list<array{key: string, before: array{module: string, description: string|null}, after: array{module: string, description: string|null}}>,
     *         roles_to_create: list<array{key: string, name: string}>,
     *         roles_to_update: list<array{key: string, before: array{name: string}, after: array{name: string}}>,
     *         grants_to_add: list<array{role: string, permission: string}>,
     *         unchanged_permissions: list<string>,
     *         unchanged_roles: list<string>,
     *         unchanged_grants: list<array{role: string, permission: string}>,
     *         conflicts: list<array{type: string, key: string, detail: string}>
     *     },
     *     counts_before: array<string, int>,
     *     counts_after: array<string, int>,
     *     applied: list<array{action: string, type: string, key: string, detail: string}>
     * }
     */
    public function run(bool $dryRun, string $confirmedDatabase): array
    {
        $activeDatabase = $this->activeDatabaseName();

        if ($confirmedDatabase === '') {
            throw new InvalidArgumentException('Exact database confirmation is required via --confirm-database.');
        }

        if ($confirmedDatabase !== $activeDatabase) {
            throw new InvalidArgumentException(
                "Confirmed database [{$confirmedDatabase}] does not match active database [{$activeDatabase}].",
            );
        }

        $countsBefore = $this->snapshotCounts();
        $plan = $this->buildPlan();

        if ($dryRun) {
            return [
                'dry_run' => true,
                'database' => $activeDatabase,
                'plan' => $plan,
                'counts_before' => $countsBefore,
                'counts_after' => $countsBefore,
                'applied' => [],
            ];
        }

        if ($plan['conflicts'] !== []) {
            throw new RuntimeException(
                'RBAC synchronization blocked by conflicts: '.$plan['conflicts'][0]['detail'],
            );
        }

        $applied = [];

        $this->acquireLock();

        try {
            DB::transaction(function () use (&$applied, $plan): void {
                $this->applyPlan($plan, $applied);

                if (app()->bound('rbacSync.induceFailure') && app('rbacSync.induceFailure') === true) {
                    throw new RuntimeException('Induced RBAC sync failure for transaction rollback testing.');
                }
            });
        } finally {
            $this->releaseLock();
        }

        return [
            'dry_run' => false,
            'database' => $activeDatabase,
            'plan' => $plan,
            'counts_before' => $countsBefore,
            'counts_after' => $this->snapshotCounts(),
            'applied' => $applied,
        ];
    }

    /**
     * @return array{
     *     permissions_to_create: list<array{key: string, module: string, description: string|null}>,
     *     permissions_to_update: list<array{key: string, before: array{module: string, description: string|null}, after: array{module: string, description: string|null}}>,
     *     roles_to_create: list<array{key: string, name: string}>,
     *     roles_to_update: list<array{key: string, before: array{name: string}, after: array{name: string}}>,
     *     grants_to_add: list<array{role: string, permission: string}>,
     *     unchanged_permissions: list<string>,
     *     unchanged_roles: list<string>,
     *     unchanged_grants: list<array{role: string, permission: string}>,
     *     conflicts: list<array{type: string, key: string, detail: string}>
     * }
     */
    public function buildPlan(): array
    {
        $permissionsToCreate = [];
        $permissionsToUpdate = [];
        $unchangedPermissions = [];
        $conflicts = [];

        foreach (RbacDefinitions::permissions() as $definition) {
            $existing = Permission::query()->where('key', $definition['key'])->first();

            if ($existing === null) {
                $permissionsToCreate[] = [
                    'key' => $definition['key'],
                    'module' => $definition['module'],
                    'description' => $definition['description'],
                ];

                continue;
            }

            if ($existing->module !== $definition['module']
                || $existing->description !== $definition['description']) {
                $permissionsToUpdate[] = [
                    'key' => $definition['key'],
                    'before' => [
                        'module' => $existing->module,
                        'description' => $existing->description,
                    ],
                    'after' => [
                        'module' => $definition['module'],
                        'description' => $definition['description'],
                    ],
                ];

                continue;
            }

            $unchangedPermissions[] = $definition['key'];
        }

        $rolesToCreate = [];
        $rolesToUpdate = [];
        $unchangedRoles = [];
        $grantsToAdd = [];
        $unchangedGrants = [];

        foreach (RbacDefinitions::systemRoles() as $roleKey => $roleDefinition) {
            $existing = Role::query()->where('key', $roleKey)->first();

            if ($existing === null) {
                $rolesToCreate[] = [
                    'key' => $roleKey,
                    'name' => $roleDefinition['name'],
                ];

                foreach ($roleDefinition['permissions'] as $permissionKey) {
                    $grantsToAdd[] = [
                        'role' => $roleKey,
                        'permission' => $permissionKey,
                    ];
                }

                continue;
            }

            if ($existing->scope !== RoleScope::System
                || $existing->parent_account_id !== null
                || $existing->organization_id !== null) {
                $conflicts[] = [
                    'type' => 'role',
                    'key' => $roleKey,
                    'detail' => "Role [{$roleKey}] exists but is not a system template role.",
                ];

                continue;
            }

            if ($existing->name !== $roleDefinition['name']) {
                $rolesToUpdate[] = [
                    'key' => $roleKey,
                    'before' => ['name' => $existing->name],
                    'after' => ['name' => $roleDefinition['name']],
                ];
            } else {
                $unchangedRoles[] = $roleKey;
            }

            $existingPermissionKeys = $existing->permissions()->pluck('key')->all();

            foreach ($roleDefinition['permissions'] as $permissionKey) {
                if (in_array($permissionKey, $existingPermissionKeys, true)) {
                    $unchangedGrants[] = [
                        'role' => $roleKey,
                        'permission' => $permissionKey,
                    ];

                    continue;
                }

                $grantsToAdd[] = [
                    'role' => $roleKey,
                    'permission' => $permissionKey,
                ];
            }
        }

        return [
            'permissions_to_create' => $permissionsToCreate,
            'permissions_to_update' => $permissionsToUpdate,
            'roles_to_create' => $rolesToCreate,
            'roles_to_update' => $rolesToUpdate,
            'grants_to_add' => $grantsToAdd,
            'unchanged_permissions' => $unchangedPermissions,
            'unchanged_roles' => $unchangedRoles,
            'unchanged_grants' => $unchangedGrants,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @param  array{
     *     permissions_to_create: list<array{key: string, module: string, description: string|null}>,
     *     permissions_to_update: list<array{key: string, before: array{module: string, description: string|null}, after: array{module: string, description: string|null}}>,
     *     roles_to_create: list<array{key: string, name: string}>,
     *     roles_to_update: list<array{key: string, before: array{name: string}, after: array{name: string}}>,
     *     grants_to_add: list<array{role: string, permission: string}>,
     *     unchanged_permissions: list<string>,
     *     unchanged_roles: list<string>,
     *     unchanged_grants: list<array{role: string, permission: string}>,
     *     conflicts: list<array{type: string, key: string, detail: string}>
     * }  $plan
     * @param  list<array{action: string, type: string, key: string, detail: string}>  $applied
     */
    private function applyPlan(array $plan, array &$applied): void
    {
        foreach ($plan['permissions_to_create'] as $permission) {
            Permission::query()->create([
                'key' => $permission['key'],
                'module' => $permission['module'],
                'description' => $permission['description'],
            ]);
            $applied[] = $this->line('created', 'permission', $permission['key'], $permission['module']);
        }

        foreach ($plan['permissions_to_update'] as $permission) {
            Permission::query()->where('key', $permission['key'])->update([
                'module' => $permission['after']['module'],
                'description' => $permission['after']['description'],
            ]);
            $applied[] = $this->line(
                'updated',
                'permission',
                $permission['key'],
                sprintf(
                    'module %s→%s; description updated',
                    $permission['before']['module'],
                    $permission['after']['module'],
                ),
            );
        }

        foreach ($plan['roles_to_create'] as $role) {
            Role::query()->create([
                'key' => $role['key'],
                'name' => $role['name'],
                'scope' => RoleScope::System,
                'parent_account_id' => null,
                'organization_id' => null,
            ]);
            $applied[] = $this->line('created', 'role', $role['key'], $role['name']);
        }

        foreach ($plan['roles_to_update'] as $role) {
            Role::query()->where('key', $role['key'])->update([
                'name' => $role['after']['name'],
            ]);
            $applied[] = $this->line(
                'updated',
                'role',
                $role['key'],
                sprintf('name %s→%s', $role['before']['name'], $role['after']['name']),
            );
        }

        foreach ($plan['grants_to_add'] as $grant) {
            $role = Role::query()->where('key', $grant['role'])->firstOrFail();
            $permission = Permission::query()->where('key', $grant['permission'])->firstOrFail();

            $role->permissions()->syncWithoutDetaching([$permission->id]);
            $applied[] = $this->line(
                'granted',
                'role_permission',
                $grant['role'].':'.$grant['permission'],
                'grant ensured',
            );
        }
    }

    /**
     * @return array{action: string, type: string, key: string, detail: string}
     */
    private function line(string $action, string $type, string $key, string $detail): array
    {
        return [
            'action' => $action,
            'type' => $type,
            'key' => $key,
            'detail' => $detail,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function snapshotCounts(): array
    {
        $tables = [
            'permissions',
            'roles',
            'role_permission',
            'parent_accounts',
            'organizations',
            'parent_account_memberships',
            'memberships',
            'parent_account_membership_role',
            'membership_role',
            'companies',
            'contacts',
            'products',
            'organization_products',
            'vendors',
            'product_categories',
            'deals',
            'teams',
            'organization_companies',
            'audit_events',
            'number_sequences',
        ];

        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = Schema::hasTable($table) ? (int) DB::table($table)->count() : 0;
        }

        return $counts;
    }

    private function activeDatabaseName(): string
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        return is_string($database) ? $database : '';
    }

    private function acquireLock(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $result = DB::selectOne('select get_lock(?, 10) as acquired', [self::LOCK_KEY]);

        if ((int) ($result->acquired ?? 0) !== 1) {
            throw new RuntimeException('Unable to acquire RBAC synchronization lock.');
        }
    }

    private function releaseLock(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        try {
            DB::selectOne('select release_lock(?) as released', [self::LOCK_KEY]);
        } catch (Throwable) {
            // Connection may already be closed during catastrophic failure; best-effort release.
        }
    }

    /**
     * @phpstan-impure
     */
    public function isLockFree(): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return true;
        }

        $result = DB::selectOne('select is_free_lock(?) as is_free', [self::LOCK_KEY]);

        return (int) ($result->is_free ?? 0) === 1;
    }
}
