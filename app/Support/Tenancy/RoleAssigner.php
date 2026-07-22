<?php

namespace App\Support\Tenancy;

use App\Enums\RoleScope;
use App\Models\Membership;
use App\Models\ParentAccountMembership;
use App\Models\Role;
use InvalidArgumentException;

final class RoleAssigner
{
    public function assignToOrganizationMembership(Membership $membership, Role $role): void
    {
        $this->assertOrganizationRole($role, $membership->organization_id);

        $membership->roles()->syncWithoutDetaching([$role->id]);
    }

    public function assignToParentMembership(ParentAccountMembership $membership, Role $role): void
    {
        $this->assertParentRole($role, $membership->parent_account_id);

        $membership->roles()->syncWithoutDetaching([$role->id]);
    }

    public function assertOrganizationRole(Role $role, int $organizationId): void
    {
        if (in_array($role->key, RbacDefinitions::parentRoleKeys(), true)) {
            throw new InvalidArgumentException("Role [{$role->key}] cannot be assigned to an organization membership.");
        }

        if ($role->scope === RoleScope::ParentAccount) {
            throw new InvalidArgumentException('Parent-scoped roles cannot be assigned to organization memberships.');
        }

        if ($role->scope === RoleScope::Organization && $role->organization_id !== $organizationId) {
            throw new InvalidArgumentException('Organization roles may only be assigned within the same organization.');
        }

        if ($role->scope === RoleScope::System && ! in_array($role->key, RbacDefinitions::organizationRoleKeys(), true)) {
            throw new InvalidArgumentException("System role [{$role->key}] is not an organization role template.");
        }
    }

    public function assertParentRole(Role $role, int $parentAccountId): void
    {
        if (in_array($role->key, RbacDefinitions::organizationRoleKeys(), true)) {
            throw new InvalidArgumentException("Role [{$role->key}] cannot be assigned to a parent membership.");
        }

        if ($role->scope === RoleScope::Organization) {
            throw new InvalidArgumentException('Organization-scoped roles cannot be assigned to parent memberships.');
        }

        if ($role->scope === RoleScope::ParentAccount && $role->parent_account_id !== $parentAccountId) {
            throw new InvalidArgumentException('Parent roles may only be assigned within the same parent account.');
        }

        if ($role->scope === RoleScope::System && ! in_array($role->key, RbacDefinitions::parentRoleKeys(), true)) {
            throw new InvalidArgumentException("System role [{$role->key}] is not a parent role template.");
        }
    }
}
