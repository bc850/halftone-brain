<?php

namespace App\Console\Commands\Tenancy;

use App\Support\Tenancy\PhaseZeroBootstrap;
use App\Support\Tenancy\RoleAssigner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

#[Signature('tenancy:bootstrap-phase-zero
    {--execute : Persist changes (default is dry-run)}
    {--confirm-database= : Exact database name that must match the active connection}
    {--user-email=brandon@pelicansigns.com : Existing bootstrap user email}
    {--parent-name=Halftone Brain : Parent account name}
    {--parent-slug=halftone-brain : Parent account slug}
    {--organization=* : Organization as "Name|slug" (defaults to approved Pelican Signs and Brim Drinkware)}')]
#[Description('Idempotent checkpoint 0C tenant/RBAC bootstrap (dry-run by default)')]
class BootstrapPhaseZeroCommand extends Command
{
    public function handle(RoleAssigner $roleAssigner): int
    {
        $dryRun = ! (bool) $this->option('execute');
        $confirmedDatabase = (string) $this->option('confirm-database');

        $this->info($dryRun ? 'Phase-zero bootstrap DRY RUN' : 'Phase-zero bootstrap EXECUTE');
        $this->line('Active database: '.config('database.connections.'.config('database.default').'.database'));

        try {
            $organizations = $this->resolveOrganizations();

            $bootstrap = new PhaseZeroBootstrap(
                roleAssigner: $roleAssigner,
                userEmail: (string) $this->option('user-email'),
                parentName: (string) $this->option('parent-name'),
                parentSlug: (string) $this->option('parent-slug'),
                organizations: $organizations,
            );

            $result = $bootstrap->run($dryRun, $confirmedDatabase);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Action', 'Type', 'Key', 'Detail'], array_map(
            fn (array $row): array => [$row['action'], $row['type'], $row['key'], $row['detail']],
            $result['summary'],
        ));

        $this->newLine();
        $this->info('Counts:');
        foreach ($result['counts'] as $table => $count) {
            $this->line("  {$table}: {$count}");
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('No writes were performed. Re-run with --execute --confirm-database=<exact-db-name> to persist.');
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array{name: string, slug: string}>
     */
    private function resolveOrganizations(): array
    {
        /** @var list<string> $raw */
        $raw = $this->option('organization');

        if ($raw === []) {
            return [
                ['name' => 'Pelican Signs', 'slug' => 'pelican-signs'],
                ['name' => 'Brim Drinkware', 'slug' => 'brim-drinkware'],
            ];
        }

        $organizations = [];

        foreach ($raw as $value) {
            $parts = explode('|', $value, 2);

            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                throw new InvalidArgumentException('Organizations must use the format "Name|slug".');
            }

            $organizations[] = [
                'name' => $parts[0],
                'slug' => $parts[1],
            ];
        }

        return $organizations;
    }
}
