<?php

use App\Models\Company;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('phase 0a tenancy and rbac tables exist', function () {
    expect(Schema::hasTable('parent_accounts'))->toBeTrue()
        ->and(Schema::hasTable('organizations'))->toBeTrue()
        ->and(Schema::hasTable('roles'))->toBeTrue()
        ->and(Schema::hasTable('permissions'))->toBeTrue()
        ->and(Schema::hasTable('parent_account_memberships'))->toBeTrue()
        ->and(Schema::hasTable('parent_account_membership_role'))->toBeTrue()
        ->and(Schema::hasTable('parent_account_membership_permission_overrides'))->toBeTrue()
        ->and(Schema::hasTable('memberships'))->toBeTrue()
        ->and(Schema::hasTable('role_permission'))->toBeTrue()
        ->and(Schema::hasTable('membership_role'))->toBeTrue()
        ->and(Schema::hasTable('membership_permission_overrides'))->toBeTrue()
        ->and(Schema::hasTable('organization_companies'))->toBeTrue()
        ->and(Schema::hasTable('team_memberships'))->toBeTrue()
        ->and(Schema::hasTable('audit_events'))->toBeTrue()
        ->and(Schema::hasTable('number_sequences'))->toBeTrue();
});

test('phase 0a tenant ownership columns exist on legacy tables', function () {
    expect(Schema::hasColumns('companies', ['parent_account_id', 'owner_id', 'sales_tax_status']))->toBeTrue()
        ->and(Schema::hasColumns('contacts', ['parent_account_id', 'company_id']))->toBeTrue()
        ->and(Schema::hasColumns('vendors', ['parent_account_id']))->toBeTrue()
        ->and(Schema::hasColumns('product_categories', ['parent_account_id']))->toBeTrue()
        ->and(Schema::hasColumns('products', ['parent_account_id']))->toBeTrue()
        ->and(Schema::hasColumns('deals', [
            'organization_id',
            'parent_account_id',
            'organization_company_id',
            'company_id',
            'owner_id',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('teams', ['organization_id', 'parent_account_id', 'name']))->toBeTrue()
        ->and(Schema::hasTable('team_user'))->toBeTrue()
        ->and(Schema::hasColumns('users', ['role', 'see_everyone']))->toBeTrue();
});

test('phase 0a organization companies omit accounting external id and activities tax tables are absent', function () {
    expect(Schema::hasColumn('organization_companies', 'accounting_customer_external_id'))->toBeFalse()
        ->and(Schema::hasTable('activities'))->toBeFalse()
        ->and(Schema::hasTable('tax_exemption_certificates'))->toBeFalse()
        ->and(Schema::hasTable('organization_vendors'))->toBeFalse()
        ->and(Schema::hasTable('product_masters'))->toBeFalse()
        ->and(Schema::hasTable('org_products'))->toBeFalse();
});

test('phase 0a key columns exist on rbac and audit tables', function () {
    expect(Schema::hasColumns('roles', ['key', 'name', 'scope', 'parent_account_id', 'organization_id']))->toBeTrue()
        ->and(Schema::hasColumns('permissions', ['key', 'module', 'description']))->toBeTrue()
        ->and(Schema::hasColumns('audit_events', [
            'parent_account_id',
            'organization_id',
            'actor_user_id',
            'action',
            'subject_type',
            'subject_id',
            'before_json',
            'after_json',
            'correlation_id',
            'created_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('audit_events', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumns('number_sequences', [
            'organization_id',
            'sequence_key',
            'prefix',
            'next_number',
            'pad_length',
        ]))->toBeTrue();
});

test('legacy crm factories still persist after phase 0a schema', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['owner_id' => $user->id]);
    $deal = Deal::factory()->create(['owner_id' => $user->id]);

    expect($company->fresh()->parent_account_id)->not->toBeNull()
        ->and($deal->fresh()->organization_id)->not->toBeNull()
        ->and($deal->fresh()->organization_company_id)->not->toBeNull()
        ->and($deal->fresh()->parent_account_id)->not->toBeNull()
        ->and($deal->fresh()->company_id)->not->toBeNull();
});

test('roles key and permissions key are globally unique', function () {
    DB::table('roles')->insert([
        'key' => 'salesperson',
        'name' => 'Salesperson',
        'scope' => 'system',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('roles')->insert([
        'key' => 'salesperson',
        'name' => 'Salesperson Duplicate',
        'scope' => 'system',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    DB::table('permissions')->insert([
        'key' => 'crm.deal.view',
        'module' => 'crm',
        'description' => 'View deals',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('permissions')->insert([
        'key' => 'crm.deal.view',
        'module' => 'crm',
        'description' => 'Duplicate',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('membership uniqueness constraints enforce one membership per user per tenant', function () {
    $ids = seedTwoOrganizationsWithUsers();

    expect(fn () => DB::table('parent_account_memberships')->insert([
        'parent_account_id' => $ids['parent_id'],
        'user_id' => $ids['user_a'],
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('memberships')->insert([
        'organization_id' => $ids['org_a'],
        'user_id' => $ids['user_a'],
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('organization company association and customer number uniqueness', function () {
    $ids = seedTwoOrganizationsWithUsers();

    DB::table('organization_companies')->insert([
        'organization_id' => $ids['org_a'],
        'company_id' => $ids['company_id'],
        'parent_account_id' => $ids['parent_id'],
        'lifecycle_status' => 'prospect',
        'relationship_status' => 'new',
        'customer_number' => 'C-100',
        'tax_posture' => 'unknown',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('organization_companies')->insert([
        'organization_id' => $ids['org_a'],
        'company_id' => $ids['company_id'],
        'parent_account_id' => $ids['parent_id'],
        'lifecycle_status' => 'prospect',
        'relationship_status' => 'new',
        'tax_posture' => 'unknown',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('organization_companies')->insert([
        'organization_id' => $ids['org_a'],
        'company_id' => $ids['company_b_id'],
        'parent_account_id' => $ids['parent_id'],
        'lifecycle_status' => 'prospect',
        'relationship_status' => 'new',
        'customer_number' => 'C-100',
        'tax_posture' => 'unknown',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    DB::table('organization_companies')->insert([
        [
            'organization_id' => $ids['org_a'],
            'company_id' => $ids['company_b_id'],
            'parent_account_id' => $ids['parent_id'],
            'lifecycle_status' => 'prospect',
            'relationship_status' => 'new',
            'customer_number' => null,
            'tax_posture' => 'unknown',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'organization_id' => $ids['org_a'],
            'company_id' => $ids['company_c_id'],
            'parent_account_id' => $ids['parent_id'],
            'lifecycle_status' => 'prospect',
            'relationship_status' => 'new',
            'customer_number' => null,
            'tax_posture' => 'unknown',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    expect(DB::table('organization_companies')->whereNull('customer_number')->count())->toBe(2);
});

test('team membership rejects membership from another organization', function () {
    $ids = seedTwoOrganizationsWithUsers();

    $teamId = DB::table('teams')->insertGetId([
        'name' => 'Crew A',
        'organization_id' => $ids['org_a'],
        'parent_account_id' => $ids['parent_id'],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('team_memberships')->insert([
        'organization_id' => $ids['org_a'],
        'team_id' => $teamId,
        'membership_id' => $ids['membership_b'],
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    DB::table('team_memberships')->insert([
        'organization_id' => $ids['org_a'],
        'team_id' => $teamId,
        'membership_id' => $ids['membership_a'],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('team_memberships')->count())->toBe(1);
});

test('required composite indexes and foreign keys exist for phase 0a', function () {
    $teamIndexes = collect(Schema::getIndexes('teams'));
    expect($teamIndexes->contains(fn (array $index): bool => $index['unique'] === true
        && $index['columns'] === ['organization_id', 'id']))->toBeTrue();

    $membershipIndexes = collect(Schema::getIndexes('memberships'));
    expect($membershipIndexes->contains(fn (array $index): bool => $index['unique'] === true
        && $index['columns'] === ['organization_id', 'id']))->toBeTrue();

    $orgCompanyIndexes = collect(Schema::getIndexes('organization_companies'));
    expect($orgCompanyIndexes->contains(fn (array $index): bool => $index['unique'] === true
        && $index['columns'] === ['organization_id', 'customer_number']))->toBeTrue();

    $teamMembershipForeignKeys = collect(Schema::getForeignKeys('team_memberships'));
    expect($teamMembershipForeignKeys->contains(fn (array $fk): bool => $fk['columns'] === ['organization_id', 'team_id']
        && $fk['foreign_table'] === 'teams'
        && $fk['foreign_columns'] === ['organization_id', 'id']))->toBeTrue();
    expect($teamMembershipForeignKeys->contains(fn (array $fk): bool => $fk['columns'] === ['organization_id', 'membership_id']
        && $fk['foreign_table'] === 'memberships'
        && $fk['foreign_columns'] === ['organization_id', 'id']))->toBeTrue();
});

test('audit parent deletion is restricted and audit has no updated_at', function () {
    expect(Schema::hasColumn('audit_events', 'updated_at'))->toBeFalse();

    $ids = seedTwoOrganizationsWithUsers();

    DB::table('audit_events')->insert([
        'parent_account_id' => $ids['parent_id'],
        'organization_id' => $ids['org_a'],
        'actor_user_id' => null,
        'action' => 'phase0.test',
        'subject_type' => 'parent_accounts',
        'subject_id' => $ids['parent_id'],
        'created_at' => now(),
    ]);

    $auditForeignKeys = collect(Schema::getForeignKeys('audit_events'));
    $parentFk = $auditForeignKeys->first(fn (array $fk): bool => $fk['columns'] === ['parent_account_id']);
    expect($parentFk)->not->toBeNull();

    $onDelete = strtolower((string) ($parentFk['on_delete'] ?? ''));
    expect(in_array($onDelete, ['restrict', 'no action'], true))->toBeTrue();

    expect(fn () => DB::table('parent_accounts')->where('id', $ids['parent_id'])->delete())
        ->toThrow(QueryException::class);
});

test('organization deletion is restricted when teams exist', function () {
    $ids = seedTwoOrganizationsWithUsers();

    DB::table('teams')->insert([
        'name' => 'Ops',
        'organization_id' => $ids['org_a'],
        'parent_account_id' => $ids['parent_id'],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('organizations')->where('id', $ids['org_a'])->delete())
        ->toThrow(QueryException::class);
});

/**
 * @return array{
 *     parent_id: int,
 *     org_a: int,
 *     org_b: int,
 *     user_a: int,
 *     user_b: int,
 *     membership_a: int,
 *     membership_b: int,
 *     company_id: int,
 *     company_b_id: int,
 *     company_c_id: int
 * }
 */
function seedTwoOrganizationsWithUsers(): array
{
    $parentId = (int) DB::table('parent_accounts')->insertGetId([
        'name' => 'Halftone Brain',
        'slug' => 'halftone-brain-'.uniqid(),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $orgA = (int) DB::table('organizations')->insertGetId([
        'parent_account_id' => $parentId,
        'name' => 'Org A',
        'slug' => 'org-a-'.uniqid(),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $orgB = (int) DB::table('organizations')->insertGetId([
        'parent_account_id' => $parentId,
        'name' => 'Org B',
        'slug' => 'org-b-'.uniqid(),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $userA = User::factory()->create();
    $userB = User::factory()->create();

    DB::table('parent_account_memberships')->insert([
        'parent_account_id' => $parentId,
        'user_id' => $userA->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $membershipA = (int) DB::table('memberships')->insertGetId([
        'organization_id' => $orgA,
        'user_id' => $userA->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $membershipB = (int) DB::table('memberships')->insertGetId([
        'organization_id' => $orgB,
        'user_id' => $userB->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $companyId = (int) Company::factory()->create([
        'owner_id' => $userA->id,
        'parent_account_id' => $parentId,
    ])->id;
    $companyBId = (int) Company::factory()->create([
        'owner_id' => $userA->id,
        'parent_account_id' => $parentId,
    ])->id;
    $companyCId = (int) Company::factory()->create([
        'owner_id' => $userA->id,
        'parent_account_id' => $parentId,
    ])->id;

    return [
        'parent_id' => $parentId,
        'org_a' => $orgA,
        'org_b' => $orgB,
        'user_a' => $userA->id,
        'user_b' => $userB->id,
        'membership_a' => $membershipA,
        'membership_b' => $membershipB,
        'company_id' => $companyId,
        'company_b_id' => $companyBId,
        'company_c_id' => $companyCId,
    ];
}
