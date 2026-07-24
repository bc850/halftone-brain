<?php

use App\Enums\MembershipStatus;
use App\Enums\SalesTaxStatus;
use App\Http\Controllers\CompanyController;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationCompany;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Route;

function cutoverTenantUser(?string $parentRole = 'parent_owner', string $orgRole = 'owner'): array
{
    return createTenantUser($orgRole, $parentRole);
}

test('dashboard redirects to last valid active organization', function () {
    $first = cutoverTenantUser();
    $secondOrg = Organization::factory()->create([
        'parent_account_id' => $first['parent']->id,
    ]);
    Membership::factory()->create([
        'organization_id' => $secondOrg->id,
        'user_id' => $first['user']->id,
        'status' => MembershipStatus::Active,
    ]);

    $this->actingAs($first['user'])
        ->withSession(['last_organization_id' => $secondOrg->id])
        ->get(route('dashboard'))
        ->assertRedirect(route('org.dashboard', $secondOrg));
});

test('stale session organization falls back to first active membership', function () {
    $ctx = cutoverTenantUser();

    $this->actingAs($ctx['user'])
        ->withSession(['last_organization_id' => 999999])
        ->get(route('dashboard'))
        ->assertRedirect(route('org.dashboard', $ctx['organization']));
});

test('unauthorized session organization is ignored', function () {
    $ctx = cutoverTenantUser();
    $other = Organization::factory()->create();

    $this->actingAs($ctx['user'])
        ->withSession(['last_organization_id' => $other->id])
        ->get(route('dashboard'))
        ->assertRedirect(route('org.dashboard', $ctx['organization']));
});

test('no active membership returns 403 on legacy dashboard', function () {
    $user = User::factory()->create();
    (new RbacSeeder)->run();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertForbidden();
});

test('legacy module indexes redirect to matching organization indexes and preserve query strings', function () {
    $ctx = cutoverTenantUser();

    $this->actingAs($ctx['user'])
        ->get(route('companies.index', ['search' => 'acme']))
        ->assertRedirect(route('org.companies.index', [
            'organization' => $ctx['organization'],
            'search' => 'acme',
        ]));
});

test('legacy redirects never leave TenantContext established after the request', function () {
    $ctx = cutoverTenantUser();

    $this->actingAs($ctx['user'])->get(route('dashboard'));

    expect(TenantContext::has())->toBeFalse();
});

test('explicit organization urls remain authoritative over session', function () {
    $ctx = cutoverTenantUser();
    $secondOrg = Organization::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
    ]);
    Membership::factory()->create([
        'organization_id' => $secondOrg->id,
        'user_id' => $ctx['user']->id,
        'status' => MembershipStatus::Active,
    ]);

    $this->actingAs($ctx['user'])
        ->withSession(['last_organization_id' => $secondOrg->id])
        ->get(route('org.dashboard', $ctx['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tenant.organization.slug', $ctx['organization']->slug));
});

test('unknown organization slug remains 404', function () {
    $ctx = cutoverTenantUser();

    $this->actingAs($ctx['user'])
        ->get('/o/does-not-exist-org/dashboard')
        ->assertNotFound();
});

test('controller defense refuses company store without TenantContext even when middleware is bypassed', function () {
    $ctx = cutoverTenantUser();

    Route::middleware(['web', 'auth', 'verified'])
        ->post('/_0d1/defense/companies', [CompanyController::class, 'store']);

    $before = Company::query()->count();

    $this->actingAs($ctx['user'])
        ->post('/_0d1/defense/companies', [
            'name' => 'Defense Probe Co',
            'sales_tax_status' => SalesTaxStatus::Taxable->value,
        ])
        ->assertStatus(409);

    expect(Company::query()->count())->toBe($before)
        ->and(TenantContext::has())->toBeFalse();
});

test('org prefixed company create still populates tenant ids', function () {
    $ctx = cutoverTenantUser();

    $this->actingAs($ctx['user'])
        ->post(route('org.companies.store', $ctx['organization']), [
            'name' => 'Org Prefixed Co',
            'sales_tax_status' => SalesTaxStatus::Taxable->value,
        ])
        ->assertRedirect();

    $company = Company::query()->where('name', 'Org Prefixed Co')->first();

    expect($company)->not->toBeNull()
        ->and((int) $company->parent_account_id)->toBe($ctx['parent']->id);

    expect(OrganizationCompany::query()
        ->where('company_id', $company->id)
        ->where('organization_id', $ctx['organization']->id)
        ->exists())->toBeTrue();
});
