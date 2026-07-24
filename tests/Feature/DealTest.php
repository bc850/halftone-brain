<?php

use App\Enums\DealStage;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\OrganizationCompany;
use App\Models\User;

test('salesmen can create deals for their companies', function () {
    $ctx = createTenantUser('salesperson');

    $company = Company::factory()->create([
        'owner_id' => $ctx['user']->id,
        'parent_account_id' => $ctx['parent']->id,
    ]);
    OrganizationCompany::factory()->create([
        'organization_id' => $ctx['organization']->id,
        'company_id' => $company->id,
        'parent_account_id' => $ctx['parent']->id,
    ]);
    $contact = Contact::factory()->create([
        'company_id' => $company->id,
        'parent_account_id' => $ctx['parent']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->post(route('org.deals.store', $ctx['organization']), [
            'name' => 'Storefront ACM signs',
            'company_id' => $company->id,
            'primary_contact_id' => $contact->id,
            'contact_ids' => [$contact->id],
            'stage' => DealStage::Lead->value,
            'amount' => 1500,
        ])
        ->assertRedirect();

    $deal = Deal::query()->where('name', 'Storefront ACM signs')->first();

    expect($deal)->not->toBeNull()
        ->and($deal->owner_id)->toBe($ctx['user']->id)
        ->and($deal->stage)->toBe(DealStage::Lead)
        ->and($deal->amount_cents)->toBe(150000)
        ->and($deal->contacts)->toHaveCount(1);

    $this->actingAs($ctx['user'])
        ->get(route('org.deals.index', $ctx['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('deals/Index')
            ->has('columns', 7));
});

test('deal stage can be updated from the pipeline', function () {
    $ctx = createTenantUser('salesperson');

    $company = Company::factory()->create([
        'owner_id' => $ctx['user']->id,
        'parent_account_id' => $ctx['parent']->id,
    ]);
    $orgCompany = OrganizationCompany::factory()->create([
        'organization_id' => $ctx['organization']->id,
        'company_id' => $company->id,
        'parent_account_id' => $ctx['parent']->id,
    ]);
    $deal = Deal::factory()->create([
        'company_id' => $company->id,
        'owner_id' => $ctx['user']->id,
        'organization_id' => $ctx['organization']->id,
        'parent_account_id' => $ctx['parent']->id,
        'organization_company_id' => $orgCompany->id,
        'stage' => DealStage::Lead,
    ]);

    $this->actingAs($ctx['user'])
        ->patch(route('org.deals.stage', [$ctx['organization'], $deal]), [
            'stage' => DealStage::Qualified->value,
        ])
        ->assertRedirect();

    expect($deal->fresh()->stage)->toBe(DealStage::Qualified);
});

test('admins can reassign deal owners on update', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $ctx['user']->forceFill(['role' => UserRole::Admin])->save();

    $salesman = User::factory()->salesman()->create();
    $other = User::factory()->salesman()->create();

    $company = Company::factory()->create([
        'owner_id' => $salesman->id,
        'parent_account_id' => $ctx['parent']->id,
    ]);
    $orgCompany = OrganizationCompany::factory()->create([
        'organization_id' => $ctx['organization']->id,
        'company_id' => $company->id,
        'parent_account_id' => $ctx['parent']->id,
    ]);
    $deal = Deal::factory()->create([
        'company_id' => $company->id,
        'owner_id' => $salesman->id,
        'organization_id' => $ctx['organization']->id,
        'parent_account_id' => $ctx['parent']->id,
        'organization_company_id' => $orgCompany->id,
    ]);

    $this->actingAs($ctx['user']->fresh())
        ->put(route('org.deals.update', [$ctx['organization'], $deal]), [
            'name' => $deal->name,
            'company_id' => $company->id,
            'stage' => DealStage::Quoting->value,
            'owner_id' => $other->id,
            'amount' => 2000,
        ])
        ->assertRedirect();

    expect($deal->fresh()->owner_id)->toBe($other->id)
        ->and($deal->fresh()->stage)->toBe(DealStage::Quoting);
});

test('salesmen cannot view another owners deals without visibility toggle', function () {
    $ownerCtx = createTenantUser('salesperson');
    $otherCtx = createTenantUser('salesperson');

    $otherCtx['membership']->update([
        'organization_id' => $ownerCtx['organization']->id,
    ]);

    $company = Company::factory()->create([
        'owner_id' => $ownerCtx['user']->id,
        'parent_account_id' => $ownerCtx['parent']->id,
    ]);
    $orgCompany = OrganizationCompany::factory()->create([
        'organization_id' => $ownerCtx['organization']->id,
        'company_id' => $company->id,
        'parent_account_id' => $ownerCtx['parent']->id,
    ]);
    $deal = Deal::factory()->create([
        'company_id' => $company->id,
        'owner_id' => $ownerCtx['user']->id,
        'organization_id' => $ownerCtx['organization']->id,
        'parent_account_id' => $ownerCtx['parent']->id,
        'organization_company_id' => $orgCompany->id,
    ]);

    $this->actingAs($otherCtx['user'])
        ->get(route('org.deals.show', [$ownerCtx['organization'], $deal]))
        ->assertForbidden();
});

test('create deal form is prefilled from a company', function () {
    $ctx = createTenantUser('salesperson');

    $company = Company::factory()->create([
        'owner_id' => $ctx['user']->id,
        'parent_account_id' => $ctx['parent']->id,
    ]);
    OrganizationCompany::factory()->create([
        'organization_id' => $ctx['organization']->id,
        'company_id' => $company->id,
        'parent_account_id' => $ctx['parent']->id,
    ]);
    $primary = Contact::factory()->primary()->create([
        'company_id' => $company->id,
        'parent_account_id' => $ctx['parent']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->get(route('org.deals.create', [
            'organization' => $ctx['organization'],
            'company_id' => $company->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('deals/Create')
            ->where('selectedCompanyId', $company->id)
            ->where('selectedPrimaryContactId', $primary->id));
});

test('create deal form is prefilled from a contact', function () {
    $ctx = createTenantUser('salesperson');

    $company = Company::factory()->create([
        'owner_id' => $ctx['user']->id,
        'parent_account_id' => $ctx['parent']->id,
    ]);
    OrganizationCompany::factory()->create([
        'organization_id' => $ctx['organization']->id,
        'company_id' => $company->id,
        'parent_account_id' => $ctx['parent']->id,
    ]);
    $contact = Contact::factory()->create([
        'company_id' => $company->id,
        'parent_account_id' => $ctx['parent']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->get(route('org.deals.create', [
            'organization' => $ctx['organization'],
            'company_id' => $company->id,
            'contact_id' => $contact->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('deals/Create')
            ->where('selectedCompanyId', $company->id)
            ->where('selectedPrimaryContactId', $contact->id));
});
