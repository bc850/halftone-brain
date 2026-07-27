<?php

namespace App\Support\Quotes;

use App\Models\NumberSequence;
use App\Models\Organization;
use App\Support\Tenancy\NumberSequenceAllocator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Fail-closed synchronization of approved quote number sequences.
 *
 * Creates missing rows only. Never updates prefix/padding, never resets
 * next_number, never allocates a quote number, never deletes sequences.
 */
final class QuoteNumberSequenceSynchronizer
{
    public const LOCK_KEY = 'halftone_quote_sequence_sync';

    /**
     * @return array{
     *     dry_run: bool,
     *     database: string,
     *     plan: array{
     *         sequences_to_create: list<array{organization_id: int|null, organization_name: string|null, organization_slug: string, key: string, prefix: string, pad_length: int, next_number: int}>,
     *         unchanged_sequences: list<array{organization_id: int, organization_name: string, organization_slug: string, key: string, prefix: string, pad_length: int, next_number: int}>,
     *         conflicts: list<array{type: string, organization_slug: string, detail: string}>,
     *         missing_organizations: list<array{organization_slug: string, detail: string}>,
     *         unrelated_sequence_count: int
     *     },
     *     counts_before: array{number_sequences: int, quote_sequences: int},
     *     counts_after: array{number_sequences: int, quote_sequences: int},
     *     applied: list<array{action: string, organization_slug: string, prefix: string, pad_length: int, next_number: int}>
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

        $countsBefore = $this->snapshotCounts();
        $plan = $this->buildPlan();

        if ($dryRun) {
            return [
                'dry_run' => true,
                'database' => $activeDatabase,
                'plan' => $plan,
                'counts_before' => $countsBefore,
                'counts_after' => $countsBefore,
                'applied' => [],
            ];
        }

        if ($this->planIsBlocked($plan)) {
            throw new RuntimeException(
                'Quote sequence synchronization blocked: '.$this->firstBlockDetail($plan),
            );
        }

        $applied = [];

        $this->acquireLock();

        try {
            DB::transaction(function () use (&$applied): void {
                // Rebuild under lock so concurrent writers cannot race the plan.
                $freshPlan = $this->buildPlan();

                if ($this->planIsBlocked($freshPlan)) {
                    throw new RuntimeException(
                        'Quote sequence synchronization blocked: '.$this->firstBlockDetail($freshPlan),
                    );
                }

                $this->applyCleanPlan($freshPlan, $applied);

                if (app()->bound('quoteSequenceSync.induceFailure') && app('quoteSequenceSync.induceFailure') === true) {
                    throw new RuntimeException('Induced quote sequence sync failure for transaction rollback testing.');
                }
            });
        } finally {
            $this->releaseLock();
        }

        return [
            'dry_run' => false,
            'database' => $activeDatabase,
            'plan' => $plan,
            'counts_before' => $countsBefore,
            'counts_after' => $this->snapshotCounts(),
            'applied' => $applied,
        ];
    }

    /**
     * Read-only diff against approved quote sequence definitions.
     *
     * @return array{
     *     sequences_to_create: list<array{organization_id: int|null, organization_name: string|null, organization_slug: string, key: string, prefix: string, pad_length: int, next_number: int}>,
     *     unchanged_sequences: list<array{organization_id: int, organization_name: string, organization_slug: string, key: string, prefix: string, pad_length: int, next_number: int}>,
     *     conflicts: list<array{type: string, organization_slug: string, detail: string}>,
     *     missing_organizations: list<array{organization_slug: string, detail: string}>,
     *     unrelated_sequence_count: int
     * }
     */
    public function buildPlan(): array
    {
        $sequencesToCreate = [];
        $unchangedSequences = [];
        $conflicts = [];
        $missingOrganizations = [];
        $managedOrganizationIds = [];

        foreach (QuoteNumberSequenceDefinitions::byOrganizationSlug() as $slug => $definition) {
            $organizations = Organization::query()
                ->where('slug', $slug)
                ->orderBy('id')
                ->get();

            if ($organizations->isEmpty()) {
                $missingOrganizations[] = [
                    'organization_slug' => $slug,
                    'detail' => "Approved organization [{$slug}] is missing.",
                ];

                continue;
            }

            if ($organizations->count() > 1) {
                $conflicts[] = [
                    'type' => 'organization',
                    'organization_slug' => $slug,
                    'detail' => "Approved organization slug [{$slug}] is duplicated ({$organizations->count()} rows).",
                ];

                continue;
            }

            /** @var Organization $organization */
            $organization = $organizations->first();
            $managedOrganizationIds[] = $organization->id;

            $existing = NumberSequence::query()
                ->where('organization_id', $organization->id)
                ->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)
                ->first();

            $rowBase = [
                'organization_id' => $organization->id,
                'organization_name' => $organization->name,
                'organization_slug' => $slug,
                'key' => NumberSequenceAllocator::KEY_QUOTE,
                'prefix' => $definition['prefix'],
                'pad_length' => $definition['pad_length'],
                'next_number' => 1,
            ];

            if ($existing === null) {
                $sequencesToCreate[] = $rowBase;

                continue;
            }

            $prefixMatches = $existing->prefix === $definition['prefix'];
            $padMatches = $existing->pad_length === $definition['pad_length'];

            if (! $prefixMatches || ! $padMatches) {
                $conflicts[] = [
                    'type' => 'sequence',
                    'organization_slug' => $slug,
                    'detail' => sprintf(
                        'Quote sequence conflict for [%s] (organization_id=%d): expected prefix [%s] pad_length [%d]; actual prefix [%s] pad_length [%d] next_number [%d].',
                        $slug,
                        $organization->id,
                        $definition['prefix'],
                        $definition['pad_length'],
                        $existing->prefix,
                        $existing->pad_length,
                        $existing->next_number,
                    ),
                ];

                continue;
            }

            $unchangedSequences[] = [
                ...$rowBase,
                'next_number' => $existing->next_number,
            ];
        }

        $unrelatedSequenceCount = NumberSequence::query()
            ->where(function ($query) use ($managedOrganizationIds): void {
                $query->where('sequence_key', '!=', NumberSequenceAllocator::KEY_QUOTE);

                if ($managedOrganizationIds === []) {
                    $query->orWhere('sequence_key', NumberSequenceAllocator::KEY_QUOTE);
                } else {
                    $query->orWhere(function ($inner) use ($managedOrganizationIds): void {
                        $inner->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)
                            ->whereNotIn('organization_id', $managedOrganizationIds);
                    });
                }
            })
            ->count();

        return [
            'sequences_to_create' => $sequencesToCreate,
            'unchanged_sequences' => $unchangedSequences,
            'conflicts' => $conflicts,
            'missing_organizations' => $missingOrganizations,
            'unrelated_sequence_count' => $unrelatedSequenceCount,
        ];
    }

    /**
     * @param  array{
     *     sequences_to_create: list<array{organization_id: int|null, organization_name: string|null, organization_slug: string, key: string, prefix: string, pad_length: int, next_number: int}>,
     *     unchanged_sequences: list<array{organization_id: int, organization_name: string, organization_slug: string, key: string, prefix: string, pad_length: int, next_number: int}>,
     *     conflicts: list<array{type: string, organization_slug: string, detail: string}>,
     *     missing_organizations: list<array{organization_slug: string, detail: string}>,
     *     unrelated_sequence_count: int
     * }  $plan
     * @param  list<array{action: string, organization_slug: string, prefix: string, pad_length: int, next_number: int}>  $applied
     */
    private function applyCleanPlan(array $plan, array &$applied): void
    {
        foreach ($plan['sequences_to_create'] as $row) {
            if ($row['organization_id'] === null) {
                throw new RuntimeException('Refusing to create quote sequence without organization_id.');
            }

            NumberSequence::query()->create([
                'organization_id' => $row['organization_id'],
                'sequence_key' => NumberSequenceAllocator::KEY_QUOTE,
                'prefix' => $row['prefix'],
                'next_number' => 1,
                'pad_length' => $row['pad_length'],
            ]);

            $applied[] = [
                'action' => 'created',
                'organization_slug' => $row['organization_slug'],
                'prefix' => $row['prefix'],
                'pad_length' => $row['pad_length'],
                'next_number' => 1,
            ];
        }
    }

    /**
     * @param  array{
     *     sequences_to_create: list<mixed>,
     *     unchanged_sequences: list<mixed>,
     *     conflicts: list<mixed>,
     *     missing_organizations: list<mixed>,
     *     unrelated_sequence_count: int
     * }  $plan
     */
    public function planIsBlocked(array $plan): bool
    {
        return $plan['conflicts'] !== [] || $plan['missing_organizations'] !== [];
    }

    /**
     * @param  array{
     *     conflicts: list<array{detail: string}>,
     *     missing_organizations: list<array{detail: string}>
     * }  $plan
     */
    private function firstBlockDetail(array $plan): string
    {
        if ($plan['missing_organizations'] !== []) {
            return $plan['missing_organizations'][0]['detail'];
        }

        if ($plan['conflicts'] !== []) {
            return $plan['conflicts'][0]['detail'];
        }

        return 'unknown block';
    }

    /**
     * @return array{number_sequences: int, quote_sequences: int}
     */
    private function snapshotCounts(): array
    {
        return [
            'number_sequences' => NumberSequence::query()->count(),
            'quote_sequences' => NumberSequence::query()
                ->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)
                ->count(),
        ];
    }

    private function activeDatabaseName(): string
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        return is_string($database) ? $database : '';
    }

    private function acquireLock(): void
    {
        if (app()->bound('quoteSequenceSync.denyLock') && app('quoteSequenceSync.denyLock') === true) {
            throw new RuntimeException('Unable to acquire quote sequence synchronization lock.');
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $result = DB::selectOne('select get_lock(?, 10) as acquired', [self::LOCK_KEY]);

        if ((int) ($result->acquired ?? 0) !== 1) {
            throw new RuntimeException('Unable to acquire quote sequence synchronization lock.');
        }
    }

    private function releaseLock(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        try {
            DB::selectOne('select release_lock(?) as released', [self::LOCK_KEY]);
        } catch (Throwable) {
            // Connection may already be closed during catastrophic failure; best-effort release.
        }
    }

    /**
     * @phpstan-impure
     */
    public function isLockFree(): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return true;
        }

        $result = DB::selectOne('select is_free_lock(?) as is_free', [self::LOCK_KEY]);

        return (int) ($result->is_free ?? 0) === 1;
    }
}
