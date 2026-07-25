<?php

namespace App\Console\Commands\Tenancy;

use App\Support\Tenancy\RbacSynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('rbac:sync
    {--execute : Persist RBAC definition changes (default is dry-run)}
    {--confirm-database= : Exact database name that must match the active connection}')]
#[Description('Synchronize system RBAC permissions, roles, and grants from RbacDefinitions (dry-run by default)')]
class SyncRbacCommand extends Command
{
    public function handle(RbacSynchronizer $synchronizer): int
    {
        $dryRun = ! (bool) $this->option('execute');
        $confirmedDatabase = (string) $this->option('confirm-database');

        $this->info($dryRun ? 'RBAC sync DRY RUN' : 'RBAC sync EXECUTE');
        $this->line('Active database: '.(string) config('database.connections.'.config('database.default').'.database'));

        try {
            $result = $synchronizer->run($dryRun, $confirmedDatabase);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $plan = $result['plan'];

        $this->newLine();
        $this->renderPlanSection('Permissions to create', $plan['permissions_to_create'], static fn (array $row): string => $row['key']);
        $this->renderPermissionUpdates($plan['permissions_to_update']);
        $this->renderPlanSection('Roles to create', $plan['roles_to_create'], static fn (array $row): string => $row['key'].' ('.$row['name'].')');
        $this->renderRoleUpdates($plan['roles_to_update']);
        $this->renderGrants('Role-permission grants to add', $plan['grants_to_add']);
        $this->renderPlanSection('Unchanged permissions', $plan['unchanged_permissions'], static fn (string $key): string => $key);
        $this->renderPlanSection('Unchanged roles', $plan['unchanged_roles'], static fn (string $key): string => $key);
        $this->line('Unchanged grants: '.count($plan['unchanged_grants']));
        $this->renderConflicts($plan['conflicts']);

        if ($result['applied'] !== []) {
            $this->newLine();
            $this->info('Applied:');
            $this->table(
                ['Action', 'Type', 'Key', 'Detail'],
                array_map(
                    fn (array $row): array => [$row['action'], $row['type'], $row['key'], $row['detail']],
                    $result['applied'],
                ),
            );
        }

        $this->newLine();
        $this->info('Counts '.($dryRun ? '(unchanged; dry-run)' : '(after):'));
        foreach ($result['counts_after'] as $table => $count) {
            $before = $result['counts_before'][$table] ?? $count;
            $suffix = $before === $count ? '' : " (was {$before})";
            $this->line("  {$table}: {$count}{$suffix}");
        }

        if ($plan['conflicts'] !== []) {
            $this->newLine();
            $this->error('Conflicts block execution. Resolve them before running with --execute.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->newLine();
            $changeCount = count($plan['permissions_to_create'])
                + count($plan['permissions_to_update'])
                + count($plan['roles_to_create'])
                + count($plan['roles_to_update'])
                + count($plan['grants_to_add']);

            if ($changeCount === 0) {
                $this->info('No RBAC changes proposed.');
            } else {
                $this->warn('No writes were performed. Re-run with --execute --confirm-database=<exact-db-name> to persist.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<mixed>  $rows
     * @param  callable(mixed): string  $formatter
     */
    private function renderPlanSection(string $title, array $rows, callable $formatter): void
    {
        $this->line(sprintf('%s (%d):', $title, count($rows)));

        if ($rows === []) {
            $this->line('  (none)');

            return;
        }

        foreach ($rows as $row) {
            $this->line('  - '.$formatter($row));
        }
    }

    /**
     * @param  list<array{key: string, before: array{module: string, description: string|null}, after: array{module: string, description: string|null}}>  $rows
     */
    private function renderPermissionUpdates(array $rows): void
    {
        $this->line(sprintf('Permissions to update (%d):', count($rows)));

        if ($rows === []) {
            $this->line('  (none)');

            return;
        }

        foreach ($rows as $row) {
            $this->line(sprintf(
                '  - %s: module [%s]→[%s]; description [%s]→[%s]',
                $row['key'],
                $row['before']['module'],
                $row['after']['module'],
                $row['before']['description'] ?? '',
                $row['after']['description'] ?? '',
            ));
        }
    }

    /**
     * @param  list<array{key: string, before: array{name: string}, after: array{name: string}}>  $rows
     */
    private function renderRoleUpdates(array $rows): void
    {
        $this->line(sprintf('Roles to update (%d):', count($rows)));

        if ($rows === []) {
            $this->line('  (none)');

            return;
        }

        foreach ($rows as $row) {
            $this->line(sprintf(
                '  - %s: name [%s]→[%s]',
                $row['key'],
                $row['before']['name'],
                $row['after']['name'],
            ));
        }
    }

    /**
     * @param  list<array{role: string, permission: string}>  $rows
     */
    private function renderGrants(string $title, array $rows): void
    {
        $this->line(sprintf('%s (%d):', $title, count($rows)));

        if ($rows === []) {
            $this->line('  (none)');

            return;
        }

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['role']][] = $row['permission'];
        }

        foreach ($grouped as $role => $permissions) {
            $this->line('  '.$role.':');
            foreach ($permissions as $permission) {
                $this->line('    - '.$permission);
            }
        }
    }

    /**
     * @param  list<array{type: string, key: string, detail: string}>  $conflicts
     */
    private function renderConflicts(array $conflicts): void
    {
        $this->line(sprintf('Conflicts (%d):', count($conflicts)));

        if ($conflicts === []) {
            $this->line('  (none)');

            return;
        }

        foreach ($conflicts as $conflict) {
            $this->line(sprintf('  - [%s] %s: %s', $conflict['type'], $conflict['key'], $conflict['detail']));
        }
    }
}
