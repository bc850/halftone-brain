<?php

use App\Enums\MembershipStatus;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\ParentAccountMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\PermissionResolver;
use App\Support\Tenancy\RbacDefinitions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;

function bootstrapDbName(): string
{
    $connection = config('database.default');

    return (string) config("database.connections.{$connection}.database");
}

function runBootstrap(array $options = [], bool $execute = false): PendingCommand
{
    $parameters = [
        '--confirm-database' => $options['confirm'] ?? bootstrapDbName(),
        '--user-email' => $options['email'] ?? 'owner@example.com',
        '--parent-name' => $options['parent_name'] ?? 'Halftone Brain',
        '--parent-slug' => $options['parent_slug'] ?? 'halftone-brain',
        '--organization' => $options['organizations'] ?? [
            'Pelican Signs|pelican-signs',
            'Brim Drinkware|brim-drinkware',
        ],
    ];

    if ($execute) {
        $parameters['--execute'] = true;
    }

    return test()->artisan('tenancy:bootstrap-phase-zero', $parameters);
}

test('dry run performs no writes', function () {
    $user = User::factory()->create([
        'email' => 'owner@example.com',
        'email_verified_at' => now(),
        'role' => 'salesman',
        'see_everyone' => false,
    ]);

    runBootstrap()->assertSuccessful();

    expect(ParentAccount::query()->count())->toBe(0)
        ->and(Organization::query()->count())->toBe(0)
        ->and(Role::query()->count())->toBe(0)
        ->and(Permission::query()->count())->toBe(0)
        ->and(Membership::query()->count())->toBe(0)
        ->and(ParentAccountMembership::query()->count())->toBe(0)
        ->and($user->fresh()->role->value)->toBe('salesman')
        ->and($user->fresh()->see_everyone)->toBeFalse();
});

test('exact database confirmation is required', function () {
    User::factory()->create([
        'email' => 'owner@example.com',
        'email_verified_at' => now(),
    ]);

    test()->artisan('tenancy:bootstrap-phase-zero', [
        '--execute' => true,
        '--user-email' => 'owner@example.com',
    ])->assertFailed();

    test()->artisan('tenancy:bootstrap-phase-zero', [
        '--execute' => true,
        '--confirm-database' => 'wrong-database-name',
        '--user-email' => 'owner@example.com',
    ])->assertFailed();

    expect(ParentAccount::query()->count())->toBe(0);
});

test('missing bootstrap user fails without partial writes', function () {
    runBootstrap(['email' => 'missing@example.com'], execute: true)->assertFailed();

    expect(ParentAccount::query()->count())->toBe(0)
        ->and(Role::query()->count())->toBe(0)
        ->and(Permission::query()->count())->toBe(0);
});

test('unverified bootstrap user fails without partial writes', function () {
    User::factory()->unverified()->create([
        'email' => 'owner@example.com',
    ]);

    runBootstrap(execute: true)->assertFailed();

    expect(ParentAccount::query()->count())->toBe(0)
        ->and(Role::query()->count())->toBe(0)
        ->and(Permission::query()->count())->toBe(0);
});

test('conflicting parent slug or name fails', function () {
    User::factory()->create([
        'email' => 'owner@example.com',
        'email_verified_at' => now(),
    ]);

    ParentAccount::factory()->create([
        'name' => 'Other Name',
        'slug' => 'halftone-brain',
    ]);

    runBootstrap(execute: true)->assertFailed();

    ParentAccount::query()->delete();

    ParentAccount::factory()->create([
        'name' => 'Halftone Brain',
        'slug' => 'other-slug',
    ]);

    runBootstrap(execute: true)->assertFailed();
});

test('conflicting organization parent or slug fails', function () {
    User::factory()->create([
        'email' => 'owner@example.com',
        'email_verified_at' => now(),
    ]);

    $otherParent = ParentAccount::factory()->create();
    Organization::factory()->create([
        'parent_account_id' => $otherParent->id,
        'name' => 'Pelican Signs',
        'slug' => 'pelican-signs',
    ]);

    runBootstrap(execute: true)->assertFailed();
});

test('correct parent and organization memberships and owner roles are created', function () {
    $user = User::factory()->create([
        'email' => 'owner@example.com',
        'email_verified_at' => now(),
        'role' => 'salesman',
        'see_everyone' => false,
    ]);

    runBootstrap(execute: true)->assertSuccessful();

    $parent = ParentAccount::query()->where('slug', 'halftone-brain')->first();
    expect($parent)->not->toBeNull()
        ->and($parent->name)->toBe('Halftone Brain')
        ->and($parent->is_active)->toBeTrue();

    $orgs = Organization::query()->where('parent_account_id', $parent->id)->orderBy('slug')->get();
    expect($orgs)->toHaveCount(2)
        ->and($orgs->pluck('slug')->all())->toBe(['brim-drinkware', 'pelican-signs']);

    $parentMembership = ParentAccountMembership::query()
        ->where('parent_account_id', $parent->id)
        ->where('user_id', $user->id)
        ->first();

    expect($parentMembership)->not->toBeNull()
        ->and($parentMembership->status)->toBe(MembershipStatus::Active)
        ->and($parentMembership->roles()->where('key', 'parent_owner')->exists())->toBeTrue();

    foreach ($orgs as $organization) {
        $membership = Membership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->first();

        expect($membership)->not->toBeNull()
            ->and($membership->status)->toBe(MembershipStatus::Active)
            ->and($membership->roles()->where('key', 'owner')->exists())->toBeTrue();
    }

    $resolver = app(PermissionResolver::class);
    $parentPermissions = $resolver->forParentMembership($parentMembership);
    expect($parentPermissions)->toContain('parent.organization.manage')
        ->and($parentPermissions)->toContain('parent.catalog.product.view_cost');

    $orgMembership = Membership::query()->where('user_id', $user->id)->first();
    $orgPermissions = $resolver->forOrganizationMembership($orgMembership);
    expect($orgPermissions)->toContain('crm.deal.view_all')
        ->and($orgPermissions)->toContain('catalog.product.view_cost');

    expect(Role::query()->count())->toBe(count(RbacDefinitions::systemRoles()))
        ->and(Permission::query()->count())->toBe(count(RbacDefinitions::permissions()));
});

test('legacy user fields remain unchanged', function () {
    $user = User::factory()->create([
        'email' => 'owner@example.com',
        'email_verified_at' => now(),
        'role' => 'salesman',
        'see_everyone' => false,
        'name' => 'Legacy Name',
    ]);

    runBootstrap(execute: true)->assertSuccessful();

    $fresh = $user->fresh();
    expect($fresh->name)->toBe('Legacy Name')
        ->and($fresh->email)->toBe('owner@example.com')
        ->and($fresh->role->value)->toBe('salesman')
        ->and($fresh->see_everyone)->toBeFalse();
});

test('running the command twice produces no duplicate records', function () {
    User::factory()->create([
        'email' => 'owner@example.com',
        'email_verified_at' => now(),
    ]);

    runBootstrap(execute: true)->assertSuccessful();
    runBootstrap(execute: true)->assertSuccessful();

    expect(ParentAccount::query()->count())->toBe(1)
        ->and(Organization::query()->count())->toBe(2)
        ->and(ParentAccountMembership::query()->count())->toBe(1)
        ->and(Membership::query()->count())->toBe(2)
        ->and(Role::query()->count())->toBe(count(RbacDefinitions::systemRoles()))
        ->and(Permission::query()->count())->toBe(count(RbacDefinitions::permissions()))
        ->and(DB::table('parent_account_membership_role')->count())->toBe(1)
        ->and(DB::table('membership_role')->count())->toBe(2)
        ->and(DB::table('number_sequences')->count())->toBe(4)
        ->and(DB::table('audit_events')->count())->toBe(2);
});

test('transaction rollback removes all partial writes after induced failure', function () {
    User::factory()->create([
        'email' => 'owner@example.com',
        'email_verified_at' => now(),
    ]);

    app()->instance('phaseZeroBootstrap.induceFailure', true);

    runBootstrap(execute: true)->assertFailed();

    expect(ParentAccount::query()->count())->toBe(0)
        ->and(Organization::query()->count())->toBe(0)
        ->and(Membership::query()->count())->toBe(0)
        ->and(ParentAccountMembership::query()->count())->toBe(0)
        ->and(Role::query()->count())->toBe(0)
        ->and(Permission::query()->count())->toBe(0)
        ->and(DB::table('role_permission')->count())->toBe(0)
        ->and(DB::table('number_sequences')->count())->toBe(0)
        ->and(DB::table('audit_events')->count())->toBe(0);
});

test('unexpected business data causes the command to refuse execution', function () {
    User::factory()->create([
        'email' => 'owner@example.com',
        'email_verified_at' => now(),
    ]);

    $parent = ParentAccount::factory()->create();
    Company::factory()->create([
        'parent_account_id' => $parent->id,
    ]);

    runBootstrap(execute: true)->assertFailed();

    expect(ParentAccount::query()->count())->toBe(1)
        ->and(Role::query()->count())->toBe(0);
});

test('bootstrap creates no customer product deal team or organization-company placeholders', function () {
    User::factory()->create([
        'email' => 'owner@example.com',
        'email_verified_at' => now(),
    ]);

    runBootstrap(execute: true)->assertSuccessful();

    expect(DB::table('companies')->count())->toBe(0)
        ->and(DB::table('contacts')->count())->toBe(0)
        ->and(DB::table('products')->count())->toBe(0)
        ->and(DB::table('vendors')->count())->toBe(0)
        ->and(DB::table('product_categories')->count())->toBe(0)
        ->and(DB::table('deals')->count())->toBe(0)
        ->and(DB::table('teams')->count())->toBe(0)
        ->and(DB::table('organization_companies')->count())->toBe(0)
        ->and(DB::table('number_sequences')->count())->toBe(4)
        ->and(DB::table('audit_events')->count())->toBe(2);
});
