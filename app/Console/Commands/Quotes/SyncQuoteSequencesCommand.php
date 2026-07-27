<?php

namespace App\Console\Commands\Quotes;

use App\Support\Quotes\QuoteNumberSequenceSynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('quotes:sync-sequences
    {--execute : Persist missing approved quote sequences (default is dry-run)}
    {--confirm-database= : Exact database name that must match the active connection}')]
#[Description('Synchronize approved quote number sequences (dry-run by default; fail-closed on conflicts)')]
class SyncQuoteSequencesCommand extends Command
{
    public function handle(QuoteNumberSequenceSynchronizer $synchronizer): int
    {
        $dryRun = ! (bool) $this->option('execute');
        $confirmedDatabase = (string) $this->option('confirm-database');

        $this->info($dryRun ? 'Quote sequence sync DRY RUN' : 'Quote sequence sync EXECUTE');
        $this->line('Active database: '.(string) config('database.connections.'.config('database.default').'.database'));

        try {
            $result = $synchronizer->run($dryRun, $confirmedDatabase);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $plan = $result['plan'];

        $this->newLine();
        $this->renderSequenceSection('Sequences to create', $plan['sequences_to_create']);
        $this->renderSequenceSection('Unchanged sequences', $plan['unchanged_sequences']);
        $this->renderConflicts($plan['conflicts']);
        $this->renderMissingOrganizations($plan['missing_organizations']);
        $this->line('Unrelated sequences left untouched: '.$plan['unrelated_sequence_count']);

        if ($result['applied'] !== []) {
            $this->newLine();
            $this->info('Applied:');
            $this->table(
                ['Action', 'Organization', 'Prefix', 'Padding', 'Next number'],
                array_map(
                    fn (array $row): array => [
                        $row['action'],
                        $row['organization_slug'],
                        $row['prefix'],
                        (string) $row['pad_length'],
                        (string) $row['next_number'],
                    ],
                    $result['applied'],
                ),
            );
        }

        $this->newLine();
        $this->info('Counts '.($dryRun ? '(unchanged; dry-run)' : '(after):'));
        foreach ($result['counts_after'] as $label => $count) {
            $before = $result['counts_before'][$label];
            $suffix = $before === $count ? '' : " (was {$before})";
            $this->line("  {$label}: {$count}{$suffix}");
        }

        if ($synchronizer->planIsBlocked($plan)) {
            $this->newLine();
            $this->error('Conflicts or missing organizations block execution. Resolve them before running with --execute.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->newLine();
            if ($plan['sequences_to_create'] === []) {
                $this->info('No quote sequence changes proposed.');
            } else {
                $this->warn('No writes were performed. Re-run with --execute --confirm-database=<exact-db-name> to persist.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{organization_id: int|null, organization_name: string|null, organization_slug: string, key: string, prefix: string, pad_length: int, next_number: int}>  $rows
     */
    private function renderSequenceSection(string $title, array $rows): void
    {
        $this->line(sprintf('%s (%d):', $title, count($rows)));

        if ($rows === []) {
            $this->line('  (none)');

            return;
        }

        foreach ($rows as $row) {
            $this->line(sprintf(
                '  - org_id=%s name=[%s] slug=[%s] key=[%s] prefix=[%s] padding=%d next_number=%d',
                $row['organization_id'] === null ? 'n/a' : (string) $row['organization_id'],
                $row['organization_name'] ?? 'n/a',
                $row['organization_slug'],
                $row['key'],
                $row['prefix'],
                $row['pad_length'],
                $row['next_number'],
            ));
        }
    }

    /**
     * @param  list<array{type: string, organization_slug: string, detail: string}>  $conflicts
     */
    private function renderConflicts(array $conflicts): void
    {
        $this->line(sprintf('Blocking conflicts (%d):', count($conflicts)));

        if ($conflicts === []) {
            $this->line('  (none)');

            return;
        }

        foreach ($conflicts as $conflict) {
            $this->line(sprintf('  - [%s] %s: %s', $conflict['type'], $conflict['organization_slug'], $conflict['detail']));
        }
    }

    /**
     * @param  list<array{organization_slug: string, detail: string}>  $missing
     */
    private function renderMissingOrganizations(array $missing): void
    {
        $this->line(sprintf('Missing organizations (%d):', count($missing)));

        if ($missing === []) {
            $this->line('  (none)');

            return;
        }

        foreach ($missing as $row) {
            $this->line(sprintf('  - %s: %s', $row['organization_slug'], $row['detail']));
        }
    }
}
