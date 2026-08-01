<?php

namespace App\Console\Commands\Integrations;

use App\Support\Integrations\Outbox\IntegrationCommandGuard;
use App\Support\Integrations\Outbox\IntegrationDeliveryProcessor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('integrations:process-deliveries
    {--limit= : Maximum deliveries to claim}
    {--organization= : Organization slug filter}
    {--worker= : Worker identity string}
    {--dry-run : Report without claiming}
    {--confirm-database= : Exact database name (required unless --dry-run)}')]
#[Description('Claim eligible integration deliveries and execute registered consumer handlers')]
class ProcessIntegrationDeliveriesCommand extends Command
{
    public function handle(
        IntegrationDeliveryProcessor $processor,
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
            $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
            $worker = $this->option('worker') !== null ? (string) $this->option('worker') : null;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Delivery processing DRY RUN' : 'Delivery processing EXECUTE');
        $this->line('Active database: '.$active);

        if ($dryRun) {
            $this->warn('No claims performed. Re-run without --dry-run and with --confirm-database=<name>.');

            return self::SUCCESS;
        }

        $result = $processor->processBatch($organizationId, $limit, $worker);

        $this->table(
            ['Metric', 'Count'],
            collect($result)->map(fn ($value, $key): array => [$key, $value])->values()->all(),
        );

        return self::SUCCESS;
    }
}
