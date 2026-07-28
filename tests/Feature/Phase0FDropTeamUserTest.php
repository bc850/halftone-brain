<?php

use App\Models\Membership;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const PHASE_0F_DROP_TEAM_USER = '2026_07_24_021938_drop_legacy_team_user_table';

/**
 * @return list<string>
 */
function phase0fRetainedLegacyColumns(): array
{
    return [
        'companies.owner_id',
        'companies.sales_tax_status',
        'organization_companies.sales_owner_membership_id',
        'deals.company_id',
        'users.role',
        'users.see_everyone',
    ];
}

function phase0fAssertRetainedSchema(): void
{
    expect(Schema::hasTable('team_memberships'))->toBeTrue();

    foreach (phase0fRetainedLegacyColumns() as $qualified) {
        [$table, $column] = explode('.', $qualified);
        expect(Schema::hasColumn($table, $column))->toBeTrue($qualified);
    }
}

function phase0fTeamUserForeignKeys(): array
{
    return collect(Schema::getForeignKeys('team_user'))
        ->map(fn (array $fk): array => [
            'columns' => $fk['columns'] ?? [],
            'foreign_table' => $fk['foreign_table'] ?? null,
            'on_delete' => strtolower((string) ($fk['on_delete'] ?? '')),
        ])
        ->sortBy(fn (array $fk): string => implode(',', $fk['columns']))
        ->values()
        ->all();
}

function phase0fHasUniqueTeamUserPivot(): bool
{
    foreach (Schema::getIndexes('team_user') as $index) {
        if (($index['unique'] ?? false) && ($index['columns'] ?? []) === ['team_id', 'user_id']) {
            return true;
        }
    }

    return false;
}

function phase0fRollbackDropMigration(): void
{
    // Phase 2D.1 (6) + Phase 2C.1 (6) + Phase 2B.1 (4) + Phase 2A (4) + Phase 1C.7D (1) + Phase 1C.7A (4) + Phase 1C.6A (2) + Phase 1C.4 (3) + Phase 1A (2) + Phase 0F (1).
    Artisan::call('migrate:rollback', ['--step' => 33, '--force' => true]);
}

function phase0fSeedTeamAndUser(): array
{
    $parent = ParentAccount::factory()->create();
    $org = Organization::factory()->create(['parent_account_id' => $parent->id]);
    $user = User::factory()->create();
    $membership = Membership::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);
    $team = Team::factory()->create([
        'organization_id' => $org->id,
        'parent_account_id' => $parent->id,
    ]);

    return compact('parent', 'org', 'user', 'membership', 'team');
}

test('fully migrated schema drops team_user and retains team_memberships plus legacy columns', function () {
    expect(Schema::hasTable('team_user'))->toBeFalse();
    phase0fAssertRetainedSchema();
});

test('migrate pretend for pending team_user drop succeeds without schema change', function () {
    Artisan::call('migrate:rollback', ['--step' => 33, '--force' => true]);

    expect(Schema::hasTable('team_user'))->toBeTrue()
        ->and(DB::table('migrations')->where('migration', PHASE_0F_DROP_TEAM_USER)->exists())->toBeFalse();

    $exit = Artisan::call('migrate', ['--pretend' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain(PHASE_0F_DROP_TEAM_USER)
        ->and($output)->toContain('team_user')
        ->and(Schema::hasTable('team_user'))->toBeTrue()
        ->and(DB::table('migrations')->where('migration', PHASE_0F_DROP_TEAM_USER)->exists())->toBeFalse();

    phase0fAssertRetainedSchema();

    Artisan::call('migrate', ['--force' => true]);
    expect(Schema::hasTable('team_user'))->toBeFalse();
});

test('empty team_user drops successfully and remigration drops again after rollback', function () {
    expect(Schema::hasTable('team_user'))->toBeFalse();

    Artisan::call('migrate:rollback', ['--step' => 33, '--force' => true]);
    expect(Schema::hasTable('team_user'))->toBeTrue()
        ->and(phase0fHasUniqueTeamUserPivot())->toBeTrue();

    $foreigns = phase0fTeamUserForeignKeys();
    expect($foreigns)->toContain([
        'columns' => ['team_id'],
        'foreign_table' => 'teams',
        'on_delete' => 'cascade',
    ])->toContain([
        'columns' => ['user_id'],
        'foreign_table' => 'users',
        'on_delete' => 'cascade',
    ]);

    $seed = phase0fSeedTeamAndUser();

    DB::table('team_user')->insert([
        'team_id' => $seed['team']->id,
        'user_id' => $seed['user']->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('team_user')->insert([
        'team_id' => $seed['team']->id,
        'user_id' => $seed['user']->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    DB::table('team_user')->where('team_id', $seed['team']->id)->delete();

    Artisan::call('migrate', ['--force' => true]);
    expect(Schema::hasTable('team_user'))->toBeFalse();
    phase0fAssertRetainedSchema();
});

test('nonempty team_user blocks migration and leaves rows intact', function () {
    Artisan::call('migrate:rollback', ['--step' => 33, '--force' => true]);
    expect(Schema::hasTable('team_user'))->toBeTrue();

    $seed = phase0fSeedTeamAndUser();
    DB::table('team_user')->insert([
        'team_id' => $seed['team']->id,
        'user_id' => $seed['user']->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => Artisan::call('migrate', ['--force' => true]))
        ->toThrow(RuntimeException::class, 'table contains 1 row(s)');

    expect(Schema::hasTable('team_user'))->toBeTrue()
        ->and(DB::table('team_user')->count())->toBe(1)
        ->and(DB::table('migrations')->where('migration', PHASE_0F_DROP_TEAM_USER)->exists())->toBeFalse();

    DB::table('team_user')->delete();
    Artisan::call('migrate', ['--force' => true]);
    expect(Schema::hasTable('team_user'))->toBeFalse();
});

test('bootstrap dry-run and execute remain safe after team_user is absent', function () {
    expect(Schema::hasTable('team_user'))->toBeFalse();

    $email = 'owner-0f@example.com';
    User::factory()->create([
        'email' => $email,
        'email_verified_at' => now(),
        'role' => 'salesman',
        'see_everyone' => false,
    ]);

    $db = (string) config('database.connections.'.config('database.default').'.database');
    $base = [
        '--confirm-database' => $db,
        '--user-email' => $email,
        '--parent-name' => 'Halftone Brain',
        '--parent-slug' => 'halftone-brain',
        '--organization' => [
            'Pelican Signs|pelican-signs',
            'Brim Drinkware|brim-drinkware',
        ],
    ];

    test()->artisan('tenancy:bootstrap-phase-zero', $base)->assertSuccessful();
    expect(ParentAccount::query()->count())->toBe(0);

    test()->artisan('tenancy:bootstrap-phase-zero', [...$base, '--execute' => true])->assertSuccessful();
    expect(ParentAccount::query()->where('slug', 'halftone-brain')->count())->toBe(1)
        ->and(Organization::query()->count())->toBe(2);

    test()->artisan('tenancy:bootstrap-phase-zero', [...$base, '--execute' => true])->assertSuccessful();
    expect(ParentAccount::query()->where('slug', 'halftone-brain')->count())->toBe(1)
        ->and(Organization::query()->count())->toBe(2)
        ->and(Schema::hasTable('team_user'))->toBeFalse();
});

test('team membership model graph and cross-organization constraints remain after drop', function () {
    expect(Schema::hasTable('team_user'))->toBeFalse()
        ->and(Schema::hasTable('team_memberships'))->toBeTrue();

    $parent = ParentAccount::factory()->create();
    $orgA = Organization::factory()->create(['parent_account_id' => $parent->id]);
    $orgB = Organization::factory()->create(['parent_account_id' => $parent->id]);
    $user = User::factory()->create();
    $membershipA = Membership::factory()->create([
        'organization_id' => $orgA->id,
        'user_id' => $user->id,
    ]);
    $membershipB = Membership::factory()->create([
        'organization_id' => $orgB->id,
        'user_id' => $user->id,
    ]);
    $team = Team::factory()->create([
        'organization_id' => $orgA->id,
        'parent_account_id' => $parent->id,
    ]);

    expect($team->teamMemberships())->toBeInstanceOf(HasMany::class)
        ->and($membershipA->teamMemberships())->toBeInstanceOf(HasMany::class);

    expect(fn () => TeamMembership::factory()->create([
        'organization_id' => $orgA->id,
        'team_id' => $team->id,
        'membership_id' => $membershipB->id,
    ]))->toThrow(QueryException::class);

    $tm = TeamMembership::factory()->create([
        'organization_id' => $orgA->id,
        'team_id' => $team->id,
        'membership_id' => $membershipA->id,
    ]);

    expect($tm->team->is($team))->toBeTrue()
        ->and($tm->membership->is($membershipA))->toBeTrue()
        ->and($team->fresh()->teamMemberships()->count())->toBe(1);
});
