<?php

use App\Enums\MembershipStatus;
use App\Enums\PermissionEffect;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationCompany;
use App\Models\ParentAccount;
use App\Models\ParentAccountMembership;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\PermissionResolver;
use App\Support\Tenancy\RoleAssigner;
use Database\Seeders\RbacSeeder;

test('same user has different permissions in two organizations', function () {
    (new RbacSeeder)->run();

    $user = User::factory()->create();
    $parent = ParentAccount::factory()->create();
    $orgSales = Organization::factory()->create(['parent_account_id' => $parent->id, 'slug' => uniqueSlug('sales')]);
    $orgProd = Organization::factory()->create(['parent_account_id' => $parent->id, 'slug' => uniqueSlug('prod')]);

    $salesMembership = Membership::factory()->create([
        'organization_id' => $orgSales->id,
        'user_id' => $user->id,
    ]);
    $prodMembership = Membership::factory()->create([
        'organization_id' => $orgProd->id,
        'user_id' => $user->id,
    ]);

    $assigner = app(RoleAssigner::class);
    $assigner->assignToOrganizationMembership($salesMembership, Role::query()->where('key', 'salesperson')->firstOrFail());
    $assigner->assignToOrganizationMembership($prodMembership, Role::query()->where('key', 'production_worker')->firstOrFail());

    $this->actingAs($user)
        ->get(route('org.companies.index', $orgSales))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('org.companies.index', $orgProd))
        ->assertForbidden();
});

test('organization admin cannot mutate shared parent catalog without parent permission', function () {
    $fixture = createTenantUser('admin');

    $product = Product::factory()->create([
        'parent_account_id' => $fixture['parent']->id,
    ]);

    $this->actingAs($fixture['user'])
        ->put(route('org.products.update', [$fixture['organization'], $product]), [
            'name' => 'Updated',
            'sku' => $product->sku,
            'unit_of_measure' => $product->unit_of_measure->value,
            'true_cost' => '10.00',
            'markup_percent' => '20',
            'list_price' => '15.00',
            'is_active' => true,
            'vendor_id' => null,
            'product_category_id' => null,
            'related_product_ids' => [],
        ])
        ->assertForbidden();
});

test('parent admin can mutate shared master data but has no automatic organization deal permission', function () {
    $fixture = createTenantUser('production_worker', 'parent_admin');

    $product = Product::factory()->create([
        'parent_account_id' => $fixture['parent']->id,
    ]);

    // Parent admin alone still needs org catalog mutation permission for ProductPolicy under tenant.
    // Grant org catalog update via override allow, keep deal.create denied by role.
    attachOrgOverride($fixture['membership'], 'catalog.product.update', PermissionEffect::Allow);
    attachOrgOverride($fixture['membership'], 'catalog.product.view', PermissionEffect::Allow);

    $this->actingAs($fixture['user'])
        ->put(route('org.products.update', [$fixture['organization'], $product]), [
            'name' => 'Parent Updated',
            'sku' => $product->sku,
            'unit_of_measure' => $product->unit_of_measure->value,
            'true_cost' => '10.00',
            'markup_percent' => '20',
            'list_price' => '15.00',
            'is_active' => true,
            'vendor_id' => null,
            'product_category_id' => null,
            'related_product_ids' => [],
            'description' => null,
            'vendor_sku' => null,
            'notes' => null,
        ])
        ->assertRedirect();

    expect($product->fresh()->name)->toBe('Parent Updated');

    $this->actingAs($fixture['user'])
        ->get(route('org.deals.create', $fixture['organization']))
        ->assertForbidden();
});

test('view_all applies only within the current organization and does not imply cost', function () {
    $fixture = createTenantUser('sales_manager');

    $companyOwnedByOther = Company::factory()->create([
        'owner_id' => User::factory()->create()->id,
        'parent_account_id' => $fixture['parent']->id,
    ]);
    OrganizationCompany::factory()->create([
        'organization_id' => $fixture['organization']->id,
        'company_id' => $companyOwnedByOther->id,
        'parent_account_id' => $fixture['parent']->id,
    ]);

    $this->actingAs($fixture['user'])
        ->get(route('org.companies.show', [$fixture['organization'], $companyOwnedByOther]))
        ->assertOk();

    $product = Product::factory()->create([
        'parent_account_id' => $fixture['parent']->id,
        'true_cost_micro_units' => 1_000_000,
    ]);

    $this->actingAs($fixture['user'])
        ->get(route('org.products.show', [$fixture['organization'], $product]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('product.true_cost')
            ->missing('product.markup_percent'));
});

test('explicit deny wins over role grants', function () {
    $fixture = createTenantUser('salesperson');
    attachOrgOverride($fixture['membership'], 'crm.company.view', PermissionEffect::Deny);

    $company = Company::factory()->create([
        'owner_id' => $fixture['user']->id,
        'parent_account_id' => $fixture['parent']->id,
    ]);
    OrganizationCompany::factory()->create([
        'organization_id' => $fixture['organization']->id,
        'company_id' => $company->id,
        'parent_account_id' => $fixture['parent']->id,
    ]);

    $this->actingAs($fixture['user'])
        ->get(route('org.companies.show', [$fixture['organization'], $company]))
        ->assertForbidden();
});

test('explicit allow grants permission not supplied by role', function () {
    $fixture = createTenantUser('production_worker');
    attachOrgOverride($fixture['membership'], 'crm.company.view', PermissionEffect::Allow);
    attachOrgOverride($fixture['membership'], 'crm.company.view_all', PermissionEffect::Allow);

    $company = Company::factory()->create([
        'owner_id' => $fixture['user']->id,
        'parent_account_id' => $fixture['parent']->id,
    ]);
    OrganizationCompany::factory()->create([
        'organization_id' => $fixture['organization']->id,
        'company_id' => $company->id,
        'parent_account_id' => $fixture['parent']->id,
    ]);

    $this->actingAs($fixture['user'])
        ->get(route('org.companies.show', [$fixture['organization'], $company]))
        ->assertOk();
});

test('wrong scope role assignment is rejected', function () {
    (new RbacSeeder)->run();

    $fixture = createTenantUser('salesperson');
    $assigner = app(RoleAssigner::class);
    $parentRole = Role::query()->where('key', 'parent_admin')->firstOrFail();

    expect(fn () => $assigner->assignToOrganizationMembership($fixture['membership'], $parentRole))
        ->toThrow(InvalidArgumentException::class);

    $orgRole = Role::query()->where('key', 'admin')->firstOrFail();
    $parentMembership = ParentAccountMembership::factory()->create([
        'parent_account_id' => $fixture['parent']->id,
        'user_id' => $fixture['user']->id,
    ]);

    expect(fn () => $assigner->assignToParentMembership($parentMembership, $orgRole))
        ->toThrow(InvalidArgumentException::class);
});

test('inactive parent membership grants no parent permissions', function () {
    $fixture = createTenantUser('admin', 'parent_admin');
    $fixture['parentMembership']->update(['status' => MembershipStatus::Inactive]);

    $resolver = app(PermissionResolver::class);
    $permissions = $resolver->forParentMembership($fixture['parentMembership']->fresh());

    expect($permissions)->toBe([]);
});

test('permission resolver deny beats role grants', function () {
    $fixture = createTenantUser('salesperson');
    attachOrgOverride($fixture['membership'], 'crm.company.create', PermissionEffect::Deny);

    $permissions = app(PermissionResolver::class)
        ->forOrganizationMembership($fixture['membership']->fresh(['roles.permissions', 'permissionOverrides.permission']));

    expect($permissions)->not->toContain('crm.company.create')
        ->and($permissions)->toContain('crm.company.view');
});
