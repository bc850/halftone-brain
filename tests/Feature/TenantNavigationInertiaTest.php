<?php

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Organization;

test('organization companies index inertia page exposes org-prefixed navigation props', function () {
    $ctx = createTenantUser('owner', 'parent_owner');

    $this->actingAs($ctx['user'])
        ->get(route('org.companies.index', $ctx['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('companies/Index')
            ->where('tenant.organization.slug', $ctx['organization']->slug)
            ->has('tenant.organizations', 1));
});

test('organization dashboard exposes both organizations for switcher', function () {
    $first = createTenantUser('owner', 'parent_owner');
    $secondOrg = Organization::factory()->create([
        'parent_account_id' => $first['parent']->id,
    ]);
    Membership::factory()->create([
        'organization_id' => $secondOrg->id,
        'user_id' => $first['user']->id,
        'status' => MembershipStatus::Active,
    ]);

    $this->actingAs($first['user'])
        ->get(route('org.dashboard', $first['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('tenant.organization.slug', $first['organization']->slug)
            ->has('tenant.organizations', 2));
});
