<?php

use App\Models\Company;
use App\Models\Contact;
use App\Models\OrganizationCompany;

test('salesmen can create contacts for their companies', function () {
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

    $this->actingAs($ctx['user'])
        ->post(route('org.contacts.store', $ctx['organization']), [
            'company_id' => $company->id,
            'first_name' => 'Jane',
            'last_name' => 'Buyer',
            'email' => 'jane@acme.test',
            'is_primary' => true,
        ])
        ->assertRedirect();

    $contact = Contact::query()->where('email', 'jane@acme.test')->first();

    expect($contact)->not->toBeNull()
        ->and($contact->is_primary)->toBeTrue()
        ->and($contact->company_id)->toBe($company->id);

    $this->actingAs($ctx['user'])
        ->get(route('org.contacts.index', $ctx['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('contacts/Index'));
});

test('salesmen cannot create contacts for other companies', function () {
    $ownerCtx = createTenantUser('salesperson');
    $otherCtx = createTenantUser('salesperson');

    $otherCtx['membership']->update([
        'organization_id' => $ownerCtx['organization']->id,
    ]);

    $company = Company::factory()->create([
        'owner_id' => $ownerCtx['user']->id,
        'parent_account_id' => $ownerCtx['parent']->id,
    ]);
    OrganizationCompany::factory()->create([
        'organization_id' => $ownerCtx['organization']->id,
        'company_id' => $company->id,
        'parent_account_id' => $ownerCtx['parent']->id,
    ]);

    $this->actingAs($otherCtx['user'])
        ->post(route('org.contacts.store', $ownerCtx['organization']), [
            'company_id' => $company->id,
            'first_name' => 'Nope',
            'last_name' => 'Access',
        ])
        ->assertInvalid(['company_id']);
});

test('marking a contact primary clears other primaries on the company', function () {
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
    $existing = Contact::factory()->primary()->create([
        'company_id' => $company->id,
        'parent_account_id' => $ctx['parent']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->post(route('org.contacts.store', $ctx['organization']), [
            'company_id' => $company->id,
            'first_name' => 'New',
            'last_name' => 'Primary',
            'is_primary' => true,
        ])
        ->assertRedirect();

    expect($existing->fresh()->is_primary)->toBeFalse()
        ->and(Contact::query()->where('company_id', $company->id)->where('is_primary', true)->count())
        ->toBe(1);
});
