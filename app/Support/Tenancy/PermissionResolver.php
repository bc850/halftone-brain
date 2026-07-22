<?php

namespace App\Support\Tenancy;

use App\Enums\MembershipStatus;
use App\Enums\PermissionEffect;
use App\Models\Membership;
use App\Models\ParentAccountMembership;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Collection;

final class PermissionResolver
{
    /**
     * @return list<string>
     */
    public function forOrganizationMembership(Membership $membership): array
    {
        if ($membership->status !== MembershipStatus::Active) {
            return [];
        }

        $membership->loadMissing(['roles.permissions', 'permissionOverrides.permission']);

        return $this->resolve(
            roles: $membership->roles,
            overrides: $membership->permissionOverrides->all(),
        );
    }

    /**
     * @return list<string>
     */
    public function forParentMembership(?ParentAccountMembership $membership): array
    {
        if ($membership === null || $membership->status !== MembershipStatus::Active) {
            return [];
        }

        $membership->loadMissing(['roles.permissions', 'permissionOverrides.permission']);

        return $this->resolve(
            roles: $membership->roles,
            overrides: $membership->permissionOverrides->all(),
        );
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @param  iterable<int, object{effect: PermissionEffect, permission: ?Permission}>  $overrides
     * @return list<string>
     */
    private function resolve(Collection $roles, iterable $overrides): array
    {
        $granted = [];

        foreach ($roles as $role) {
            foreach ($role->permissions as $permission) {
                /** @var Permission $permission */
                $granted[$permission->key] = true;
            }
        }

        $denied = [];

        foreach ($overrides as $override) {
            $key = $override->permission?->key;
            if ($key === null) {
                continue;
            }

            if ($override->effect === PermissionEffect::Deny) {
                $denied[$key] = true;
                unset($granted[$key]);
            }
        }

        foreach ($overrides as $override) {
            $key = $override->permission?->key;
            if ($key === null) {
                continue;
            }

            if ($override->effect === PermissionEffect::Allow && ! isset($denied[$key])) {
                $granted[$key] = true;
            }
        }

        return array_keys($granted);
    }
}
