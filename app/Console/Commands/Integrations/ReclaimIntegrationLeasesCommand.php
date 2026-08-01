<?php

namespace App\Console\Commands\Integrations;

use App\Support\Integrations\Outbox\IntegrationCommandGuard;
use App\Support\Integrations\Outbox\IntegrationLeaseReclaimer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('integrations:reclaim-leases
    {--organization= : Organization slug filter}
    {--dry-run : Report without reclaiming}
    {--confirm-database= : Exact database name (required unless --dry-run)}')]
#[Description('Reclaim expired outbox and delivery processing leases')]
class ReclaimIntegrationLeasesCommand extends Command
{
    public function handle(
        IntegrationLeaseReclaimer $reclaimer,
        IntegrationCommandGuard $guard,
    ): int {
        try {
            $dryRun = (bool) $this->option('dry-run');
            $active = $guard->assertDatabase(
                $this->option('confirm-database') !== null ? (string) $this->option('confirm-database') : null,
                requireConfirmation: ! $dryRun,
            );
            $organizationId = $guard->resolveOrganizationId(
                $this->option('organization') !== null ? (string) $this->option('organization') : null,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Lease reclaim DRY RUN' : 'Lease reclaim EXECUTE');
        $this->line('Active database: '.$active);

        if ($dryRun) {
            $this->warn('No reclaim performed. Re-run without --dry-run and with --confirm-database=<name>.');

            return self::SUCCESS;
        }

        $result = $reclaimer->reclaimExpired($organizationId);

        $this->table(
            ['Metric', 'Count'],
            collect($result)->map(fn ($value, $key): array => [$key, $value])->values()->all(),
        );

        return self::SUCCESS;
    }
}
