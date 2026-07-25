<?php

use App\Enums\MembershipStatus;
use App\Http\Middleware\ResolveTenantContextFromRoute;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationCompany;
use App\Models\OrganizationProduct;
use App\Models\Team;
use App\Models\Vendor;
use Illuminate\Support\Facades\Route;

test('cross parent company access returns 404', function () {
    $fixture = createTenantUser('admin');
    $other = createTenantUser('admin');

    $company = Company::factory()->create([
        'owner_id' => $other['user']->id,
        'parent_account_id' => $other['parent']->id,
    ]);
    OrganizationCompany::factory()->create([
        'organization_id' => $other['organization']->id,
        'company_id' => $company->id,
        'parent_account_id' => $other['parent']->id,
    ]);

    $this->actingAs($fixture['user'])
        ->get(route('org.companies.show', [$fixture['organization'], $company]))
        ->assertNotFound();
});

test('cross organization deal access returns 404', function () {
    $fixture = createTenantUser('admin');
    $sibling = Organization::factory()->create([
        'parent_account_id' => $fixture['parent']->id,
        'slug' => uniqueSlug('sibling'),
    ]);

    Membership::factory()->create([
        'organization_id' => $sibling->id,
        'user_id' => $fixture['user']->id,
        'status' => MembershipStatus::Active,
    ]);

    $company = Company::factory()->create([
        'owner_id' => $fixture['user']->id,
        'parent_account_id' => $fixture['parent']->id,
    ]);
    $orgCompany = OrganizationCompany::factory()->create([
        'organization_id' => $sibling->id,
        'company_id' => $company->id,
        'parent_account_id' => $fixture['parent']->id,
    ]);
    $deal = Deal::factory()->create([
        'company_id' => $company->id,
        'owner_id' => $fixture['user']->id,
        'organization_id' => $sibling->id,
        'parent_account_id' => $fixture['parent']->id,
        'organization_company_id' => $orgCompany->id,
    ]);

    $this->actingAs($fixture['user'])
        ->get(route('org.deals.show', [$fixture['organization'], $deal]))
        ->assertNotFound();
});

test('cross parent product access returns 404', function () {
    $fixture = createTenantUser('admin');
    $other = createTenantUser('admin');

    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $other['parent']->id,
        'organization_id' => $other['organization']->id,
    ]);

    $this->actingAs($fixture['user'])
        ->get(route('org.products.show', [$fixture['organization'], $op]))
        ->assertNotFound();
});

test('cross parent vendor access returns 404', function () {
    $fixture = createTenantUser('admin');
    $other = createTenantUser('admin');

    $vendor = Vendor::factory()->create([
        'parent_account_id' => $other['parent']->id,
    ]);

    $this->actingAs($fixture['user'])
        ->get(route('org.vendors.show', [$fixture['organization'], $vendor]))
        ->assertNotFound();
});

test('contact visibility follows organization company association', function () {
    $fixture = createTenantUser('admin');
    $associated = Company::factory()->create([
        'owner_id' => $fixture['user']->id,
        'parent_account_id' => $fixture['parent']->id,
    ]);
    OrganizationCompany::factory()->create([
        'organization_id' => $fixture['organization']->id,
        'company_id' => $associated->id,
        'parent_account_id' => $fixture['parent']->id,
    ]);
    $visibleContact = Contact::factory()->create([
        'company_id' => $associated->id,
        'parent_account_id' => $fixture['parent']->id,
    ]);

    $unassociated = Company::factory()->create([
        'owner_id' => $fixture['user']->id,
        'parent_account_id' => $fixture['parent']->id,
    ]);
    $hiddenContact = Contact::factory()->create([
        'company_id' => $unassociated->id,
        'parent_account_id' => $fixture['parent']->id,
    ]);

    $this->actingAs($fixture['user'])
        ->get(route('org.contacts.show', [$fixture['organization'], $visibleContact]))
        ->assertOk();

    $this->actingAs($fixture['user'])
        ->get(route('org.contacts.show', [$fixture['organization'], $hiddenContact]))
        ->assertNotFound();
});

test('team binding is scoped to current organization', function () {
    $fixture = createTenantUser('admin');
    $other = createTenantUser('admin');

    $team = Team::factory()->create([
        'organization_id' => $other['organization']->id,
        'parent_account_id' => $other['parent']->id,
    ]);

    Route::middleware(['web', 'auth', 'verified', ResolveTenantContextFromRoute::class])
        ->get('/o/{organization}/__team-probe/{team}', fn (Organization $organization, Team $team) => response((string) $team->id));

    $this->actingAs($fixture['user'])
        ->get('/o/'.$fixture['organization']->slug.'/__team-probe/'.$team->id)
        ->assertNotFound();
});
