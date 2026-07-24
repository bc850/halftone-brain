<?php

use App\Enums\PermissionEffect;
use App\Enums\SalesTaxStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\OrganizationCompany;
use App\Models\User;

test('guests cannot view companies', function () {
    $this->get(route('companies.index'))->assertRedirect(route('login'));
});

test('owners can create and view their companies under organization routes', function () {
    $ctx = createTenantUser('owner', 'parent_owner');

    $this->actingAs($ctx['user'])
        ->post(route('org.companies.store', $ctx['organization']), [
            'name' => 'Acme Signs',
            'sales_tax_status' => SalesTaxStatus::Taxable->value,
            'email' => 'billing@acme.test',
            'phone' => '555-0100',
        ])
        ->assertRedirect();

    $company = Company::query()->where('name', 'Acme Signs')->first();

    expect($company)->not->toBeNull()
        ->and($company->owner_id)->toBe($ctx['user']->id)
        ->and((int) $company->parent_account_id)->toBe($ctx['parent']->id);

    expect(OrganizationCompany::query()
        ->where('company_id', $company->id)
        ->where('organization_id', $ctx['organization']->id)
        ->exists())->toBeTrue();

    $this->actingAs($ctx['user'])
        ->get(route('org.companies.index', $ctx['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('companies/Index')
            ->has('companies.data', 1));
});

test('salespeople cannot view another owners company without view_all', function () {
    $ownerCtx = createTenantUser('owner', 'parent_owner');
    $otherCtx = createTenantUser('salesperson');

    // Put the salesperson into the owner's organization.
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
        ->get(route('org.companies.show', [$ownerCtx['organization'], $company]))
        ->assertForbidden();

    attachOrgOverride($otherCtx['membership']->fresh(), 'crm.company.view_all', PermissionEffect::Allow);

    $this->actingAs($otherCtx['user'])
        ->get(route('org.companies.show', [$ownerCtx['organization'], $company]))
        ->assertOk();
});

test('parent owners can assign company owners through organization routes', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $salesman = User::factory()->salesman()->create();

    // Legacy owner_id assignment still requires isAdmin() in controller.
    $ctx['user']->forceFill(['role' => UserRole::Admin])->save();

    $this->actingAs($ctx['user']->fresh())
        ->post(route('org.companies.store', $ctx['organization']), [
            'name' => 'Owned By Sales',
            'owner_id' => $salesman->id,
            'sales_tax_status' => SalesTaxStatus::Exempt->value,
        ])
        ->assertRedirect();

    expect(Company::query()->where('name', 'Owned By Sales')->value('owner_id'))
        ->toBe($salesman->id);
});

test('admins cannot assign project managers as company owners', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $ctx['user']->forceFill(['role' => UserRole::Admin])->save();
    $projectManager = User::factory()->projectManager()->create();

    $this->actingAs($ctx['user']->fresh())
        ->post(route('org.companies.store', $ctx['organization']), [
            'name' => 'Bad Owner Co',
            'owner_id' => $projectManager->id,
            'sales_tax_status' => SalesTaxStatus::Taxable->value,
        ])
        ->assertSessionHasErrors('owner_id');
});
