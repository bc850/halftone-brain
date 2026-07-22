<?php

use App\Enums\SalesTaxStatus;
use App\Models\Company;
use App\Models\User;

test('guests cannot view companies', function () {
    $this->get(route('companies.index'))->assertRedirect(route('login'));
});

test('salesmen can create and view their companies', function () {
    $salesman = User::factory()->salesman()->create();

    $this->actingAs($salesman)
        ->post(route('companies.store'), [
            'name' => 'Acme Signs',
            'sales_tax_status' => SalesTaxStatus::Taxable->value,
            'email' => 'billing@acme.test',
            'phone' => '555-0100',
        ])
        ->assertRedirect();

    $company = Company::query()->where('name', 'Acme Signs')->first();

    expect($company)->not->toBeNull()
        ->and($company->owner_id)->toBe($salesman->id);

    $this->actingAs($salesman)
        ->get(route('companies.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('companies/Index')
            ->has('companies.data', 1));
});

test('salesmen cannot view another salesmans companies unless toggled', function () {
    $owner = User::factory()->salesman()->create();
    $other = User::factory()->salesman()->create();
    $company = Company::factory()->create(['owner_id' => $owner->id]);

    $this->actingAs($other)
        ->get(route('companies.show', $company))
        ->assertForbidden();

    $other->update(['see_everyone' => true]);

    $this->actingAs($other)
        ->get(route('companies.show', $company))
        ->assertOk();
});

test('admins can assign company owners', function () {
    $admin = User::factory()->admin()->create();
    $salesman = User::factory()->salesman()->create();

    $this->actingAs($admin)
        ->post(route('companies.store'), [
            'name' => 'Owned By Sales',
            'owner_id' => $salesman->id,
            'sales_tax_status' => SalesTaxStatus::Exempt->value,
        ])
        ->assertRedirect();

    expect(Company::query()->where('name', 'Owned By Sales')->value('owner_id'))
        ->toBe($salesman->id);
});

test('admins cannot assign project managers as company owners', function () {
    $admin = User::factory()->admin()->create();
    $projectManager = User::factory()->projectManager()->create();

    $this->actingAs($admin)
        ->post(route('companies.store'), [
            'name' => 'Bad Owner Co',
            'owner_id' => $projectManager->id,
            'sales_tax_status' => SalesTaxStatus::Taxable->value,
        ])
        ->assertSessionHasErrors('owner_id');
});
