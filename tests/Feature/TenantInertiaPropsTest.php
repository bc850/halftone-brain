<?php

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\ParentAccountMembership;
use App\Models\User;

test('guests receive an empty tenant payload', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tenant.organization', null)
            ->where('tenant.permissions', [])
            ->where('tenant.parentPermissions', [])
            ->where('tenant.canManageParent', false)
            ->where('tenant.organizations', []));
});

test('authenticated legacy pages expose accessible organizations without active tenant context', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => MembershipStatus::Active,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tenant.organization', null)
            ->where('tenant.permissions', [])
            ->where('tenant.parentPermissions', [])
            ->where('tenant.canManageParent', false)
            ->has('tenant.organizations', 1)
            ->where('tenant.organizations.0.slug', $organization->slug));
});

test('organization routes expose tenant context and accessible organizations', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    ParentAccountMembership::factory()->create([
        'parent_account_id' => $organization->parent_account_id,
        'user_id' => $user->id,
        'status' => MembershipStatus::Active,
    ]);

    Membership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => MembershipStatus::Active,
    ]);

    $this->actingAs($user)
        ->get(route('org.dashboard', $organization))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tenant.organization.id', $organization->id)
            ->where('tenant.organization.slug', $organization->slug)
            ->has('tenant.permissions')
            ->has('tenant.parentPermissions')
            ->where('tenant.canManageParent', false)
            ->has('tenant.organizations', 1));
});
