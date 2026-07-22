<?php

use App\Models\Company;
use App\Models\Contact;
use App\Models\User;

test('salesmen can create contacts for their companies', function () {
    $salesman = User::factory()->salesman()->create();
    $company = Company::factory()->create(['owner_id' => $salesman->id]);

    $this->actingAs($salesman)
        ->post(route('contacts.store'), [
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

    $this->actingAs($salesman)
        ->get(route('contacts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('contacts/Index'));
});

test('salesmen cannot create contacts for other companies', function () {
    $owner = User::factory()->salesman()->create();
    $other = User::factory()->salesman()->create();
    $company = Company::factory()->create(['owner_id' => $owner->id]);

    $this->actingAs($other)
        ->post(route('contacts.store'), [
            'company_id' => $company->id,
            'first_name' => 'Nope',
            'last_name' => 'Access',
        ])
        ->assertInvalid(['company_id']);
});

test('marking a contact primary clears other primaries on the company', function () {
    $salesman = User::factory()->salesman()->create();
    $company = Company::factory()->create(['owner_id' => $salesman->id]);
    $existing = Contact::factory()->primary()->create(['company_id' => $company->id]);

    $this->actingAs($salesman)
        ->post(route('contacts.store'), [
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
