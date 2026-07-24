<?php

use App\Enums\MembershipStatus;
use App\Http\Middleware\ResolveTenantContextFromRoute;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\RoleAssigner;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Route;

test('successful organization request leaves no static tenant context', function () {
    $fixture = createTenantUser('admin');

    $this->actingAs($fixture['user'])
        ->get(route('org.dashboard', $fixture['organization']))
        ->assertOk();

    expect(TenantContext::has())->toBeFalse()
        ->and(TenantContext::getOptional())->toBeNull();
});

test('tenant context is cleared when downstream code throws', function () {
    Route::middleware(['web', 'auth', 'verified', ResolveTenantContextFromRoute::class])
        ->get('/o/{organization}/__tenant-lifecycle-boom', function () {
            expect(TenantContext::has())->toBeTrue();

            throw new RuntimeException('tenant lifecycle boom');
        });

    $fixture = createTenantUser('admin');

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($fixture['user'])
        ->get('/o/'.$fixture['organization']->slug.'/__tenant-lifecycle-boom'))
        ->toThrow(RuntimeException::class, 'tenant lifecycle boom');

    expect(TenantContext::has())->toBeFalse()
        ->and(TenantContext::getOptional())->toBeNull();
});

test('sequential requests in the same process cannot reuse previous organization context', function () {
    (new RbacSeeder)->run();

    $user = User::factory()->create();
    $parent = ParentAccount::factory()->create();
    $orgA = Organization::factory()->create([
        'parent_account_id' => $parent->id,
        'slug' => uniqueSlug('life-a'),
    ]);
    $orgB = Organization::factory()->create([
        'parent_account_id' => $parent->id,
        'slug' => uniqueSlug('life-b'),
    ]);

    $membershipA = Membership::factory()->create([
        'organization_id' => $orgA->id,
        'user_id' => $user->id,
        'status' => MembershipStatus::Active,
    ]);
    $membershipB = Membership::factory()->create([
        'organization_id' => $orgB->id,
        'user_id' => $user->id,
        'status' => MembershipStatus::Active,
    ]);

    $assigner = app(RoleAssigner::class);
    $assigner->assignToOrganizationMembership(
        $membershipA,
        Role::query()->where('key', 'salesperson')->firstOrFail(),
    );
    $assigner->assignToOrganizationMembership(
        $membershipB,
        Role::query()->where('key', 'admin')->firstOrFail(),
    );

    $captured = [];

    Route::middleware(['web', 'auth', 'verified', ResolveTenantContextFromRoute::class])
        ->get('/o/{organization}/__tenant-lifecycle-capture', function () use (&$captured) {
            $tenant = TenantContext::get();
            $captured[] = [
                'organization_id' => $tenant->organizationId,
                'membership_id' => $tenant->organizationMembershipId,
                'permissions' => $tenant->organizationPermissions,
            ];

            return response('captured');
        });

    $this->actingAs($user)
        ->get('/o/'.$orgA->slug.'/__tenant-lifecycle-capture')
        ->assertOk();

    expect(TenantContext::has())->toBeFalse();

    $this->actingAs($user)
        ->get('/o/'.$orgB->slug.'/__tenant-lifecycle-capture')
        ->assertOk();

    expect(TenantContext::has())->toBeFalse()
        ->and($captured)->toHaveCount(2)
        ->and($captured[0]['organization_id'])->toBe($orgA->id)
        ->and($captured[0]['membership_id'])->toBe($membershipA->id)
        ->and($captured[0]['permissions'])->not->toContain('crm.deal.view_all')
        ->and($captured[1]['organization_id'])->toBe($orgB->id)
        ->and($captured[1]['membership_id'])->toBe($membershipB->id)
        ->and($captured[1]['permissions'])->toContain('crm.deal.view_all')
        ->and($captured[1]['membership_id'])->not->toBe($captured[0]['membership_id']);
});

test('organization a then organization b resolves only b membership and permissions', function () {
    (new RbacSeeder)->run();

    $user = User::factory()->create();
    $parent = ParentAccount::factory()->create();
    $orgA = Organization::factory()->create([
        'parent_account_id' => $parent->id,
        'slug' => uniqueSlug('iso-a'),
    ]);
    $orgB = Organization::factory()->create([
        'parent_account_id' => $parent->id,
        'slug' => uniqueSlug('iso-b'),
    ]);

    $membershipA = Membership::factory()->create([
        'organization_id' => $orgA->id,
        'user_id' => $user->id,
        'status' => MembershipStatus::Active,
    ]);
    $membershipB = Membership::factory()->create([
        'organization_id' => $orgB->id,
        'user_id' => $user->id,
        'status' => MembershipStatus::Active,
    ]);

    $assigner = app(RoleAssigner::class);
    $assigner->assignToOrganizationMembership(
        $membershipA,
        Role::query()->where('key', 'salesperson')->firstOrFail(),
    );
    $assigner->assignToOrganizationMembership(
        $membershipB,
        Role::query()->where('key', 'admin')->firstOrFail(),
    );

    $this->actingAs($user)
        ->get(route('org.dashboard', $orgA))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tenant.organization.id', $orgA->id)
            ->where(
                'tenant.permissions',
                fn ($permissions) => ! in_array('crm.deal.view_all', $permissions->toArray(), true),
            ));

    expect(TenantContext::has())->toBeFalse();

    $this->actingAs($user)
        ->get(route('org.dashboard', $orgB))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tenant.organization.id', $orgB->id)
            ->where('tenant.organization.slug', $orgB->slug)
            ->where(
                'tenant.permissions',
                fn ($permissions) => in_array('crm.deal.view_all', $permissions->toArray(), true),
            ));

    expect(TenantContext::has())->toBeFalse();
});

test('organization request followed by legacy request does not expose tenant context', function () {
    $fixture = createTenantUser('admin');

    $this->actingAs($fixture['user'])
        ->get(route('org.dashboard', $fixture['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tenant.organization.id', $fixture['organization']->id));

    expect(TenantContext::has())->toBeFalse();

    $this->actingAs($fixture['user'])
        ->get(route('dashboard'))
        ->assertRedirect(route('org.dashboard', $fixture['organization']));

    expect(TenantContext::has())->toBeFalse()
        ->and(TenantContext::getOptional())->toBeNull();
});

test('unauthenticated organization route follows authentication redirect not tenant 401', function () {
    $organization = Organization::factory()->create();

    $this->get(route('org.dashboard', $organization))
        ->assertRedirect(route('login'))
        ->assertStatus(302);

    expect(TenantContext::has())->toBeFalse();
});
