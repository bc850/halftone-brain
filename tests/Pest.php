<?php

use App\Enums\MembershipStatus;
use App\Enums\PermissionEffect;
use App\Models\Membership;
use App\Models\MembershipPermissionOverride;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\ParentAccountMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\RoleAssigner;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/Support/phase2e3c_helpers.php';

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/**
 * @return array{
 *     user: User,
 *     parent: ParentAccount,
 *     organization: Organization,
 *     membership: Membership,
 *     parentMembership: ParentAccountMembership|null
 * }
 */
function createTenantUser(string $orgRoleKey = 'salesperson', ?string $parentRoleKey = null): array
{
    (new RbacSeeder)->run();

    $user = User::factory()->create();
    $parent = ParentAccount::factory()->create();
    $organization = Organization::factory()->create([
        'parent_account_id' => $parent->id,
    ]);

    $membership = Membership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => MembershipStatus::Active,
    ]);

    $assigner = app(RoleAssigner::class);
    $orgRole = Role::query()->where('key', $orgRoleKey)->firstOrFail();
    $assigner->assignToOrganizationMembership($membership, $orgRole);

    $parentMembership = null;

    if ($parentRoleKey !== null) {
        $parentMembership = ParentAccountMembership::factory()->create([
            'parent_account_id' => $parent->id,
            'user_id' => $user->id,
            'status' => MembershipStatus::Active,
        ]);

        $parentRole = Role::query()->where('key', $parentRoleKey)->firstOrFail();
        $assigner->assignToParentMembership($parentMembership, $parentRole);
    }

    return [
        'user' => $user,
        'parent' => $parent,
        'organization' => $organization,
        'membership' => $membership->fresh(['roles.permissions']),
        'parentMembership' => $parentMembership?->fresh(['roles.permissions']),
    ];
}

function attachOrgOverride(Membership $membership, string $permissionKey, PermissionEffect $effect): void
{
    $permission = Permission::query()->where('key', $permissionKey)->firstOrFail();

    MembershipPermissionOverride::query()->create([
        'membership_id' => $membership->id,
        'permission_id' => $permission->id,
        'effect' => $effect,
        'reason' => 'test override',
        'created_by_user_id' => $membership->user_id,
    ]);
}

function uniqueSlug(string $prefix): string
{
    return $prefix.'-'.Str::lower(Str::random(8));
}
