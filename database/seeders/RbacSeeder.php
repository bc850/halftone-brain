<?php

namespace Database\Seeders;

use App\Enums\RoleScope;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Tenancy\RbacDefinitions;
use Illuminate\Database\Seeder;

/**
 * Seeds system permission and role templates.
 * Do not run against the primary database during checkpoint 0B.
 * Tests and the future 0C backfill may invoke this seeder.
 */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RbacDefinitions::permissions() as $permission) {
            Permission::query()->updateOrCreate(
                ['key' => $permission['key']],
                [
                    'module' => $permission['module'],
                    'description' => $permission['description'],
                ],
            );
        }

        foreach (RbacDefinitions::systemRoles() as $key => $roleDefinition) {
            $role = Role::query()->updateOrCreate(
                ['key' => $key],
                [
                    'name' => $roleDefinition['name'],
                    'scope' => RoleScope::System,
                    'parent_account_id' => null,
                    'organization_id' => null,
                ],
            );

            $permissionIds = Permission::query()
                ->whereIn('key', $roleDefinition['permissions'])
                ->pluck('id');

            $role->permissions()->sync($permissionIds);
        }
    }
}
