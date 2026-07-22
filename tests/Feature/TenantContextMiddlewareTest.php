<?php

use App\Enums\MembershipStatus;
use App\Http\Middleware\ResolveTenantContextFromRoute;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationCompany;
use App\Models\ParentAccount;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

test('unknown organization returns 404', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/o/does-not-exist/dashboard')
        ->assertNotFound();
});

test('user without membership returns 404', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('org.dashboard', $organization))
        ->assertNotFound();
});

test('inactive membership returns 403', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();

    Membership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => MembershipStatus::Inactive,
    ]);

    $this->actingAs($user)
        ->get(route('org.dashboard', $organization))
        ->assertForbidden();
});

test('inactive organization cannot be entered', function () {
    $organization = Organization::factory()->create(['is_active' => false]);
    $user = User::factory()->create();

    Membership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => MembershipStatus::Active,
    ]);

    $this->actingAs($user)
        ->get('/o/'.$organization->slug.'/dashboard')
        ->assertNotFound();
});

test('inactive parent account cannot be entered', function () {
    $parent = ParentAccount::factory()->create(['is_active' => false]);
    $organization = Organization::factory()->create([
        'parent_account_id' => $parent->id,
        'is_active' => true,
    ]);
    $user = User::factory()->create();

    Membership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => MembershipStatus::Active,
    ]);

    $this->actingAs($user)
        ->get(route('org.dashboard', $organization))
        ->assertNotFound();
});

test('url organization wins over session organization', function () {
    (new RbacSeeder)->run();

    $user = User::factory()->create();
    $parent = ParentAccount::factory()->create();
    $orgA = Organization::factory()->create(['parent_account_id' => $parent->id, 'slug' => uniqueSlug('org-a')]);
    $orgB = Organization::factory()->create(['parent_account_id' => $parent->id, 'slug' => uniqueSlug('org-b')]);

    foreach ([$orgA, $orgB] as $organization) {
        Membership::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => MembershipStatus::Active,
        ]);
    }

    $this->actingAs($user)
        ->withSession(['last_organization_id' => $orgA->id])
        ->get(route('org.dashboard', $orgB))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tenant.organization.id', $orgB->id)
            ->where('tenant.organization.slug', $orgB->slug));
});

test('two organization urls remain independent across requests', function () {
    $fixtureA = createTenantUser('admin');
    $orgB = Organization::factory()->create([
        'parent_account_id' => $fixtureA['parent']->id,
        'slug' => uniqueSlug('org-b'),
    ]);

    Membership::factory()->create([
        'organization_id' => $orgB->id,
        'user_id' => $fixtureA['user']->id,
        'status' => MembershipStatus::Active,
    ]);

    $this->actingAs($fixtureA['user'])
        ->get(route('org.dashboard', $fixtureA['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tenant.organization.id', $fixtureA['organization']->id));

    $this->actingAs($fixtureA['user'])
        ->get(route('org.dashboard', $orgB))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tenant.organization.id', $orgB->id));
});

test('resolve tenant middleware runs after auth and before substitute bindings', function () {
    $priority = app(Kernel::class)->getMiddlewarePriority();

    $resolveIndex = array_search(ResolveTenantContextFromRoute::class, $priority, true);
    $bindingsIndex = array_search(SubstituteBindings::class, $priority, true);

    expect($resolveIndex)->not->toBeFalse()
        ->and($bindingsIndex)->not->toBeFalse()
        ->and($resolveIndex)->toBeLessThan($bindingsIndex);

    Route::middleware(['web', 'auth', 'verified', ResolveTenantContextFromRoute::class])
        ->get('/o/{organization}/__tenant-binding-probe/{company}', function (Organization $organization, Company $company) {
            expect(TenantContext::has())->toBeTrue()
                ->and(TenantContext::get()->organizationId)->toBe($organization->id)
                ->and($company->id)->toBeInt();

            return response('ok');
        });

    $fixture = createTenantUser('admin');
    $company = Company::factory()->create([
        'owner_id' => $fixture['user']->id,
        'parent_account_id' => $fixture['parent']->id,
    ]);
    OrganizationCompany::factory()->create([
        'organization_id' => $fixture['organization']->id,
        'company_id' => $company->id,
        'parent_account_id' => $fixture['parent']->id,
    ]);

    $foreign = createTenantUser('admin');
    $foreignCompany = Company::factory()->create([
        'owner_id' => $foreign['user']->id,
        'parent_account_id' => $foreign['parent']->id,
    ]);
    OrganizationCompany::factory()->create([
        'organization_id' => $foreign['organization']->id,
        'company_id' => $foreignCompany->id,
        'parent_account_id' => $foreign['parent']->id,
    ]);

    $this->actingAs($fixture['user'])
        ->get('/o/'.$fixture['organization']->slug.'/__tenant-binding-probe/'.$company->id)
        ->assertOk()
        ->assertSee('ok');

    $this->actingAs($fixture['user'])
        ->get('/o/'.$fixture['organization']->slug.'/__tenant-binding-probe/'.$foreignCompany->id)
        ->assertNotFound();
});

test('tenant context never uses legacy role fallback', function () {
    $fixture = createTenantUser('production_worker');
    $fixture['user']->forceFill([
        'role' => 'admin',
        'see_everyone' => true,
    ])->save();

    $this->actingAs($fixture['user'])
        ->get(route('org.companies.index', $fixture['organization']))
        ->assertForbidden();
});
