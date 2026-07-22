<?php

use App\Enums\DealStage;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;

test('salesmen can create deals for their companies', function () {
    $salesman = User::factory()->salesman()->create();
    $company = Company::factory()->create(['owner_id' => $salesman->id]);
    $contact = Contact::factory()->create(['company_id' => $company->id]);

    $this->actingAs($salesman)
        ->post(route('deals.store'), [
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
        ->and($deal->owner_id)->toBe($salesman->id)
        ->and($deal->stage)->toBe(DealStage::Lead)
        ->and($deal->amount_cents)->toBe(150000)
        ->and($deal->contacts)->toHaveCount(1);

    $this->actingAs($salesman)
        ->get(route('deals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('deals/Index')
            ->has('columns', 7));
});

test('deal stage can be updated from the pipeline', function () {
    $salesman = User::factory()->salesman()->create();
    $company = Company::factory()->create(['owner_id' => $salesman->id]);
    $deal = Deal::factory()->create([
        'company_id' => $company->id,
        'owner_id' => $salesman->id,
        'stage' => DealStage::Lead,
    ]);

    $this->actingAs($salesman)
        ->patch(route('deals.stage', $deal), [
            'stage' => DealStage::Qualified->value,
        ])
        ->assertRedirect();

    expect($deal->fresh()->stage)->toBe(DealStage::Qualified);
});

test('admins can reassign deal owners on update', function () {
    $admin = User::factory()->admin()->create();
    $salesman = User::factory()->salesman()->create();
    $other = User::factory()->salesman()->create();
    $company = Company::factory()->create(['owner_id' => $salesman->id]);
    $deal = Deal::factory()->create([
        'company_id' => $company->id,
        'owner_id' => $salesman->id,
    ]);

    $this->actingAs($admin)
        ->put(route('deals.update', $deal), [
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
    $owner = User::factory()->salesman()->create();
    $other = User::factory()->salesman()->create();
    $company = Company::factory()->create(['owner_id' => $owner->id]);
    $deal = Deal::factory()->create([
        'company_id' => $company->id,
        'owner_id' => $owner->id,
    ]);

    $this->actingAs($other)
        ->get(route('deals.show', $deal))
        ->assertForbidden();
});

test('create deal form is prefilled from a company', function () {
    $salesman = User::factory()->salesman()->create();
    $company = Company::factory()->create(['owner_id' => $salesman->id]);
    $primary = Contact::factory()->primary()->create(['company_id' => $company->id]);

    $this->actingAs($salesman)
        ->get(route('deals.create', ['company_id' => $company->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('deals/Create')
            ->where('selectedCompanyId', $company->id)
            ->where('selectedPrimaryContactId', $primary->id));
});

test('create deal form is prefilled from a contact', function () {
    $salesman = User::factory()->salesman()->create();
    $company = Company::factory()->create(['owner_id' => $salesman->id]);
    $contact = Contact::factory()->create(['company_id' => $company->id]);

    $this->actingAs($salesman)
        ->get(route('deals.create', [
            'company_id' => $company->id,
            'contact_id' => $contact->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('deals/Create')
            ->where('selectedCompanyId', $company->id)
            ->where('selectedPrimaryContactId', $contact->id));
});
