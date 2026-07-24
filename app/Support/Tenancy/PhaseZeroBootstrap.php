<?php

namespace App\Support\Tenancy;

use App\Enums\MembershipStatus;
use App\Enums\RoleScope;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\NumberSequence;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\ParentAccountMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class PhaseZeroBootstrap
{
    public const LOCK_KEY = 'halftone_phase_zero_bootstrap';

    public const COMPLETION_ACTION = 'phase0.backfill.completed';

    /**
     * @param  list<array{name: string, slug: string}>  $organizations
     * @param  array<string, array{customer: array{prefix: string, pad_length: int}, deal: array{prefix: string, pad_length: int}}>  $sequenceDefinitions
     */
    public function __construct(
        private RoleAssigner $roleAssigner,
        private Auditor $auditor,
        private string $userEmail = 'brandon@pelicansigns.com',
        private string $parentName = 'Halftone Brain',
        private string $parentSlug = 'halftone-brain',
        private array $organizations = [
            ['name' => 'Pelican Signs', 'slug' => 'pelican-signs'],
            ['name' => 'Brim Drinkware', 'slug' => 'brim-drinkware'],
        ],
        private string $parentRoleKey = 'parent_owner',
        private string $organizationRoleKey = 'owner',
        private array $sequenceDefinitions = [
            'pelican-signs' => [
                'customer' => ['prefix' => 'PEL-C-', 'pad_length' => 5],
                'deal' => ['prefix' => 'PEL-D-', 'pad_length' => 5],
            ],
            'brim-drinkware' => [
                'customer' => ['prefix' => 'BRIM-C-', 'pad_length' => 5],
                'deal' => ['prefix' => 'BRIM-D-', 'pad_length' => 5],
            ],
        ],
    ) {}

    /**
     * @return array{
     *     dry_run: bool,
     *     database: string,
     *     summary: list<array{action: string, type: string, key: string, detail: string}>,
     *     counts: array<string, int>
     * }
     */
    public function run(bool $dryRun, string $confirmedDatabase): array
    {
        $activeDatabase = $this->activeDatabaseName();

        if ($confirmedDatabase === '') {
            throw new InvalidArgumentException('Exact database confirmation is required via --confirm-database.');
        }

        if ($confirmedDatabase !== $activeDatabase) {
            throw new InvalidArgumentException(
                "Confirmed database [{$confirmedDatabase}] does not match active database [{$activeDatabase}].",
            );
        }

        $this->assertBusinessTablesAreEmpty();
        $this->resolveBootstrapUser();

        $summary = [];

        if ($dryRun) {
            $this->plan($summary);

            return [
                'dry_run' => true,
                'database' => $activeDatabase,
                'summary' => $summary,
                'counts' => $this->snapshotCounts(),
            ];
        }

        $this->acquireLock();

        try {
            DB::transaction(function () use (&$summary): void {
                $this->upsertRbac($summary);
                $parent = $this->upsertParentAccount($summary);
                $organizations = $this->upsertOrganizations($parent, $summary);
                $user = $this->resolveBootstrapUser();
                $this->upsertParentMembership($parent, $user, $summary);

                foreach ($organizations as $organization) {
                    $this->upsertOrganizationMembership($organization, $user, $summary);
                }

                $this->upsertNumberSequences($organizations, $summary);
                $this->appendCompletionAudits($parent, $organizations, $summary);

                if (app()->bound('phaseZeroBootstrap.induceFailure') && app('phaseZeroBootstrap.induceFailure') === true) {
                    throw new RuntimeException('Induced bootstrap failure for transaction rollback testing.');
                }
            });
        } finally {
            $this->releaseLock();
        }

        return [
            'dry_run' => false,
            'database' => $activeDatabase,
            'summary' => $summary,
            'counts' => $this->snapshotCounts(),
        ];
    }

    /**
     * @param  list<array{action: string, type: string, key: string, detail: string}>  $summary
     */
    private function plan(array &$summary): void
    {
        $summary[] = $this->line('proposed', 'rbac', 'permissions', count(RbacDefinitions::permissions()).' system permissions');
        $summary[] = $this->line('proposed', 'rbac', 'roles', count(RbacDefinitions::systemRoles()).' system roles');
        $summary[] = $this->line('proposed', 'parent_account', $this->parentSlug, $this->parentName);
        foreach ($this->organizations as $organization) {
            $summary[] = $this->line('proposed', 'organization', $organization['slug'], $organization['name']);
        }
        $summary[] = $this->line('proposed', 'user', $this->userEmail, 'locate existing verified user');
        $summary[] = $this->line('proposed', 'parent_membership', $this->parentSlug, 'parent_owner via RoleAssigner');
        foreach ($this->organizations as $organization) {
            $summary[] = $this->line('proposed', 'organization_membership', $organization['slug'], 'owner via RoleAssigner');
        }
        $this->planSequences($summary);
        $this->planCompletionAudits($summary);
        $summary[] = $this->line('proposed', 'backfill', 'crm_catalog', 'verified no-op (business tables empty)');
    }

    /**
     * @param  list<array{action: string, type: string, key: string, detail: string}>  $summary
     */
    private function planSequences(array &$summary): void
    {
        foreach ($this->organizations as $organization) {
            $definitions = $this->sequenceDefinitions[$organization['slug']] ?? null;

            if ($definitions === null) {
                throw new RuntimeException("Missing sequence definitions for organization [{$organization['slug']}].");
            }

            $existingOrg = Organization::query()->where('slug', $organization['slug'])->first();

            foreach ($definitions as $key => $definition) {
                if ($existingOrg === null) {
                    $summary[] = $this->line(
                        'proposed',
                        'number_sequence',
                        $organization['slug'].':'.$key,
                        $definition['prefix'].str_pad('1', $definition['pad_length'], '0', STR_PAD_LEFT),
                    );

                    continue;
                }

                $existing = NumberSequence::query()
                    ->where('organization_id', $existingOrg->id)
                    ->where('sequence_key', $key)
                    ->first();

                if ($existing === null) {
                    $summary[] = $this->line(
                        'proposed',
                        'number_sequence',
                        $organization['slug'].':'.$key,
                        $definition['prefix'].str_pad('1', $definition['pad_length'], '0', STR_PAD_LEFT),
                    );

                    continue;
                }

                if ($existing->prefix !== $definition['prefix'] || $existing->pad_length !== $definition['pad_length']) {
                    $summary[] = $this->line(
                        'conflicting',
                        'number_sequence',
                        $organization['slug'].':'.$key,
                        "existing {$existing->prefix}/{$existing->pad_length}",
                    );

                    throw new RuntimeException(
                        "Conflicting number sequence [{$key}] for [{$organization['slug']}].",
                    );
                }

                $summary[] = $this->line(
                    'existing',
                    'number_sequence',
                    $organization['slug'].':'.$key,
                    "next_number={$existing->next_number}",
                );
            }
        }
    }

    /**
     * @param  list<array{action: string, type: string, key: string, detail: string}>  $summary
     */
    private function planCompletionAudits(array &$summary): void
    {
        $parent = ParentAccount::query()->where('slug', $this->parentSlug)->first();

        foreach ($this->organizations as $organization) {
            $existingOrg = Organization::query()->where('slug', $organization['slug'])->first();

            if ($parent === null || $existingOrg === null) {
                $summary[] = $this->line('proposed', 'audit_event', self::COMPLETION_ACTION, $organization['slug']);

                continue;
            }

            $existing = AuditEvent::query()
                ->where('action', self::COMPLETION_ACTION)
                ->where('parent_account_id', $parent->id)
                ->where('organization_id', $existingOrg->id)
                ->first();

            if ($existing === null) {
                $summary[] = $this->line('proposed', 'audit_event', self::COMPLETION_ACTION, $organization['slug']);

                continue;
            }

            if ($existing->parent_account_id !== $parent->id || $existing->organization_id !== $existingOrg->id) {
                throw new RuntimeException("Conflicting completion audit for [{$organization['slug']}].");
            }

            $summary[] = $this->line('existing', 'audit_event', self::COMPLETION_ACTION, $organization['slug']);
        }
    }

    /**
     * @param  list<Organization>  $organizations
     * @param  list<array{action: string, type: string, key: string, detail: string}>  $summary
     */
    private function upsertNumberSequences(array $organizations, array &$summary): void
    {
        foreach ($organizations as $organization) {
            $definitions = $this->sequenceDefinitions[$organization->slug] ?? null;

            if ($definitions === null) {
                throw new RuntimeException("Missing sequence definitions for organization [{$organization->slug}].");
            }

            foreach ($definitions as $key => $definition) {
                $existing = NumberSequence::query()
                    ->where('organization_id', $organization->id)
                    ->where('sequence_key', $key)
                    ->first();

                if ($existing === null) {
                    NumberSequence::query()->create([
                        'organization_id' => $organization->id,
                        'sequence_key' => $key,
                        'prefix' => $definition['prefix'],
                        'next_number' => 1,
                        'pad_length' => $definition['pad_length'],
                    ]);
                    $summary[] = $this->line(
                        'created',
                        'number_sequence',
                        $organization->slug.':'.$key,
                        $definition['prefix'].str_pad('1', $definition['pad_length'], '0', STR_PAD_LEFT),
                    );

                    continue;
                }

                if ($existing->prefix !== $definition['prefix'] || $existing->pad_length !== $definition['pad_length']) {
                    throw new RuntimeException(
                        "Conflicting number sequence [{$key}] for [{$organization->slug}]: refusing to rewrite prefix/padding.",
                    );
                }

                $summary[] = $this->line(
                    'existing',
                    'number_sequence',
                    $organization->slug.':'.$key,
                    "next_number={$existing->next_number}",
                );
            }
        }
    }

    /**
     * @param  list<Organization>  $organizations
     * @param  list<array{action: string, type: string, key: string, detail: string}>  $summary
     */
    private function appendCompletionAudits(ParentAccount $parent, array $organizations, array &$summary): void
    {
        $correlationId = (string) Str::uuid();

        foreach ($organizations as $organization) {
            $existing = AuditEvent::query()
                ->where('action', self::COMPLETION_ACTION)
                ->where('parent_account_id', $parent->id)
                ->where('organization_id', $organization->id)
                ->get();

            if ($existing->count() > 1) {
                throw new RuntimeException("Multiple completion audits found for [{$organization->slug}].");
            }

            if ($existing->count() === 1) {
                $event = $existing->first();

                if ($event->parent_account_id !== $parent->id || $event->organization_id !== $organization->id) {
                    throw new RuntimeException("Conflicting completion audit for [{$organization->slug}].");
                }

                $summary[] = $this->line('existing', 'audit_event', self::COMPLETION_ACTION, $organization->slug);

                continue;
            }

            $this->auditor->append(
                parentAccount: $parent,
                action: self::COMPLETION_ACTION,
                subjectType: Organization::class,
                subjectId: $organization->id,
                organization: $organization,
                actor: null,
                before: null,
                after: [
                    'checkpoint' => '0C-3',
                    'parent' => [
                        'name' => $parent->name,
                        'slug' => $parent->slug,
                    ],
                    'organization' => [
                        'name' => $organization->name,
                        'slug' => $organization->slug,
                    ],
                    'counts' => [
                        'parent_accounts' => ParentAccount::query()->count(),
                        'organizations' => Organization::query()->count(),
                        'memberships' => Membership::query()->count(),
                        'parent_account_memberships' => ParentAccountMembership::query()->count(),
                        'roles' => Role::query()->count(),
                        'permissions' => Permission::query()->count(),
                        'number_sequences' => NumberSequence::query()->count(),
                    ],
                ],
                ip: null,
                userAgent: null,
                correlationId: $correlationId,
            );

            $summary[] = $this->line('created', 'audit_event', self::COMPLETION_ACTION, $organization->slug);
        }
    }

    /**
     * @param  list<array{action: string, type: string, key: string, detail: string}>  $summary
     */
    private function upsertRbac(array &$summary): void
    {
        foreach (RbacDefinitions::permissions() as $permission) {
            $existing = Permission::query()->where('key', $permission['key'])->first();

            if ($existing === null) {
                Permission::query()->create([
                    'key' => $permission['key'],
                    'module' => $permission['module'],
                    'description' => $permission['description'],
                ]);
                $summary[] = $this->line('created', 'permission', $permission['key'], $permission['module']);

                continue;
            }

            if ($existing->module !== $permission['module'] || $existing->description !== $permission['description']) {
                throw new RuntimeException("Conflicting permission [{$permission['key']}] already exists with different attributes.");
            }

            $summary[] = $this->line('existing', 'permission', $permission['key'], $permission['module']);
        }

        foreach (RbacDefinitions::systemRoles() as $key => $roleDefinition) {
            $existing = Role::query()->where('key', $key)->first();

            if ($existing === null) {
                $role = Role::query()->create([
                    'key' => $key,
                    'name' => $roleDefinition['name'],
                    'scope' => RoleScope::System,
                    'parent_account_id' => null,
                    'organization_id' => null,
                ]);
                $summary[] = $this->line('created', 'role', $key, $roleDefinition['name']);
            } else {
                if ($existing->scope !== RoleScope::System
                    || $existing->parent_account_id !== null
                    || $existing->organization_id !== null
                    || $existing->name !== $roleDefinition['name']) {
                    throw new RuntimeException("Conflicting role [{$key}] already exists with different attributes.");
                }

                $role = $existing;
                $summary[] = $this->line('existing', 'role', $key, $roleDefinition['name']);
            }

            $permissionIds = Permission::query()
                ->whereIn('key', $roleDefinition['permissions'])
                ->pluck('id');

            if ($permissionIds->count() !== count(array_unique($roleDefinition['permissions']))) {
                throw new RuntimeException("Role [{$key}] is missing one or more defined permissions.");
            }

            $role->permissions()->syncWithoutDetaching($permissionIds->all());
            $summary[] = $this->line('upserted', 'role_permissions', $key, $permissionIds->count().' assignments ensured');
        }
    }

    /**
     * @param  list<array{action: string, type: string, key: string, detail: string}>  $summary
     */
    private function upsertParentAccount(array &$summary): ParentAccount
    {
        $bySlug = ParentAccount::query()->where('slug', $this->parentSlug)->first();
        $byName = ParentAccount::query()->where('name', $this->parentName)->first();

        if ($bySlug !== null && $byName !== null && $bySlug->id !== $byName->id) {
            throw new RuntimeException('Parent account name and slug resolve to different records.');
        }

        $existing = $bySlug ?? $byName;

        if ($existing !== null) {
            if ($existing->slug !== $this->parentSlug || $existing->name !== $this->parentName) {
                throw new RuntimeException('Conflicting parent account name or slug already exists.');
            }

            if (! $existing->is_active) {
                throw new RuntimeException('Existing parent account is inactive; refusing to mutate.');
            }

            $summary[] = $this->line('existing', 'parent_account', $existing->slug, $existing->name);

            return $existing;
        }

        $parent = ParentAccount::query()->create([
            'name' => $this->parentName,
            'slug' => $this->parentSlug,
            'is_active' => true,
        ]);

        $summary[] = $this->line('created', 'parent_account', $parent->slug, $parent->name);

        return $parent;
    }

    /**
     * @param  list<array{action: string, type: string, key: string, detail: string}>  $summary
     * @return list<Organization>
     */
    private function upsertOrganizations(ParentAccount $parent, array &$summary): array
    {
        $organizations = [];

        foreach ($this->organizations as $definition) {
            $bySlug = Organization::query()->where('slug', $definition['slug'])->first();
            $byName = Organization::query()
                ->where('parent_account_id', $parent->id)
                ->where('name', $definition['name'])
                ->first();

            if ($bySlug !== null && $byName !== null && $bySlug->id !== $byName->id) {
                throw new RuntimeException("Organization name and slug conflict for [{$definition['slug']}].");
            }

            $existing = $bySlug ?? $byName;

            if ($existing !== null) {
                if ($existing->parent_account_id !== $parent->id) {
                    throw new RuntimeException("Organization [{$definition['slug']}] belongs to a different parent account.");
                }

                if ($existing->slug !== $definition['slug'] || $existing->name !== $definition['name']) {
                    throw new RuntimeException("Conflicting organization name or slug for [{$definition['slug']}].");
                }

                if (! $existing->is_active) {
                    throw new RuntimeException("Organization [{$definition['slug']}] is inactive; refusing to mutate.");
                }

                $summary[] = $this->line('existing', 'organization', $existing->slug, $existing->name);
                $organizations[] = $existing;

                continue;
            }

            $organization = Organization::query()->create([
                'parent_account_id' => $parent->id,
                'name' => $definition['name'],
                'slug' => $definition['slug'],
                'is_active' => true,
            ]);

            $summary[] = $this->line('created', 'organization', $organization->slug, $organization->name);
            $organizations[] = $organization;
        }

        return $organizations;
    }

    private function resolveBootstrapUser(): User
    {
        $users = User::query()->where('email', $this->userEmail)->get();

        if ($users->count() !== 1) {
            throw new RuntimeException("Bootstrap user [{$this->userEmail}] must match exactly one account.");
        }

        $user = $users->first();

        if (! $user->hasVerifiedEmail()) {
            throw new RuntimeException("Bootstrap user [{$this->userEmail}] email is not verified.");
        }

        return $user;
    }

    /**
     * @param  list<array{action: string, type: string, key: string, detail: string}>  $summary
     */
    private function upsertParentMembership(ParentAccount $parent, User $user, array &$summary): void
    {
        $membership = ParentAccountMembership::query()
            ->where('parent_account_id', $parent->id)
            ->where('user_id', $user->id)
            ->first();

        if ($membership === null) {
            $membership = ParentAccountMembership::query()->create([
                'parent_account_id' => $parent->id,
                'user_id' => $user->id,
                'status' => MembershipStatus::Active,
            ]);
            $summary[] = $this->line('created', 'parent_membership', $parent->slug, 'user #'.$user->id);
        } else {
            if ($membership->status !== MembershipStatus::Active) {
                throw new RuntimeException('Existing parent membership is not active; refusing to mutate.');
            }

            $summary[] = $this->line('existing', 'parent_membership', $parent->slug, 'user #'.$user->id);
        }

        $role = Role::query()->where('key', $this->parentRoleKey)->firstOrFail();
        $this->roleAssigner->assignToParentMembership($membership, $role);
        $summary[] = $this->line('assigned', 'parent_role', $this->parentRoleKey, 'membership #'.$membership->id);
    }

    /**
     * @param  list<array{action: string, type: string, key: string, detail: string}>  $summary
     */
    private function upsertOrganizationMembership(Organization $organization, User $user, array &$summary): void
    {
        $membership = Membership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->first();

        if ($membership === null) {
            $membership = Membership::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'status' => MembershipStatus::Active,
            ]);
            $summary[] = $this->line('created', 'organization_membership', $organization->slug, 'user #'.$user->id);
        } else {
            if ($membership->status !== MembershipStatus::Active) {
                throw new RuntimeException("Existing membership for [{$organization->slug}] is not active; refusing to mutate.");
            }

            $summary[] = $this->line('existing', 'organization_membership', $organization->slug, 'user #'.$user->id);
        }

        $role = Role::query()->where('key', $this->organizationRoleKey)->firstOrFail();
        $this->roleAssigner->assignToOrganizationMembership($membership, $role);
        $summary[] = $this->line('assigned', 'organization_role', $this->organizationRoleKey, $organization->slug);
    }

    private function assertBusinessTablesAreEmpty(): void
    {
        $tables = [
            'companies',
            'contacts',
            'products',
            'vendors',
            'product_categories',
            'deals',
            'teams',
            // Checked only while present; skipped after the 0F drop migration.
            'team_user',
            'organization_companies',
            'team_memberships',
        ];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $count = DB::table($table)->count();

            if ($count > 0) {
                throw new RuntimeException(
                    "Unexpected business data found in [{$table}] ({$count} rows). Refusing bootstrap; no automatic backfill.",
                );
            }
        }
    }

    /**
     * @return array{action: string, type: string, key: string, detail: string}
     */
    private function line(string $action, string $type, string $key, string $detail): array
    {
        return [
            'action' => $action,
            'type' => $type,
            'key' => $key,
            'detail' => $detail,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function snapshotCounts(): array
    {
        $tables = [
            'parent_accounts',
            'organizations',
            'parent_account_memberships',
            'memberships',
            'roles',
            'permissions',
            'role_permission',
            'parent_account_membership_role',
            'membership_role',
            'companies',
            'contacts',
            'products',
            'vendors',
            'product_categories',
            'deals',
            'teams',
            'organization_companies',
            'audit_events',
            'number_sequences',
        ];

        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
        }

        return $counts;
    }

    private function activeDatabaseName(): string
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        return is_string($database) ? $database : '';
    }

    private function acquireLock(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $result = DB::selectOne('select get_lock(?, 10) as acquired', [self::LOCK_KEY]);

        if ((int) ($result->acquired ?? 0) !== 1) {
            throw new RuntimeException('Unable to acquire phase-zero bootstrap lock.');
        }
    }

    private function releaseLock(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::selectOne('select release_lock(?) as released', [self::LOCK_KEY]);
    }
}
