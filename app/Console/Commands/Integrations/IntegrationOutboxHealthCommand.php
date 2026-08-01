<?php

namespace App\Console\Commands\Integrations;

use App\Support\Integrations\Outbox\IntegrationCommandGuard;
use App\Support\Integrations\Outbox\IntegrationOutboxHealthReporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('integrations:outbox-health
    {--organization= : Organization slug filter}')]
#[Description('Read-only integration outbox and delivery health counts')]
class IntegrationOutboxHealthCommand extends Command
{
    public function handle(
        IntegrationOutboxHealthReporter $reporter,
        IntegrationCommandGuard $guard,
    ): int {
        try {
            $active = $guard->assertDatabase(null, requireConfirmation: false);
            $organizationId = $guard->resolveOrganizationId(
                $this->option('organization') !== null ? (string) $this->option('organization') : null,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $report = $reporter->report($organizationId);

        $this->info('Integration outbox health');
        $this->line('Active database: '.$active);
        $this->newLine();

        $this->line('Outbox by status:');
        foreach ($report['outbox_by_status'] as $status => $count) {
            $this->line("  {$status}: {$count}");
        }

        $this->newLine();
        $this->line('Deliveries by status:');
        foreach ($report['deliveries_by_status'] as $status => $count) {
            $this->line("  {$status}: {$count}");
        }

        $this->newLine();
        $this->line('Deliveries by consumer:');
        if ($report['deliveries_by_consumer'] === []) {
            $this->line('  (none)');
        } else {
            foreach ($report['deliveries_by_consumer'] as $consumer => $statuses) {
                $parts = collect($statuses)->map(fn ($count, $status): string => "{$status}={$count}")->implode(', ');
                $this->line("  {$consumer}: {$parts}");
            }
        }

        $this->newLine();
        $this->line('oldest_eligible_pending_age_seconds: '.($report['oldest_eligible_pending_age_seconds'] ?? 'null'));
        $this->line('oldest_blocked_configuration_age_seconds: '.($report['oldest_blocked_configuration_age_seconds'] ?? 'null'));
        $this->line('last_successful_delivery_at: '.($report['last_successful_delivery_at'] ?? 'null'));
        $this->line('dead_delivery_count: '.$report['dead_delivery_count']);
        $this->line('currently_leased_count: '.$report['currently_leased_count']);
        $this->line('expired_lease_count: '.$report['expired_lease_count']);

        return self::SUCCESS;
    }
}
