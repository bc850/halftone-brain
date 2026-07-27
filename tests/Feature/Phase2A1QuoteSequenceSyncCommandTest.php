<?php

use App\Models\NumberSequence;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Support\Quotes\QuoteNumberSequenceDefinitions;
use App\Support\Quotes\QuoteNumberSequenceSynchronizer;
use App\Support\Tenancy\NumberSequenceAllocator;
use App\Support\Tenancy\PhaseZeroBootstrap;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;

function quoteSeqSyncDbName(): string
{
    $connection = config('database.default');

    return (string) config("database.connections.{$connection}.database");
}

/**
 * @param  array{confirm?: string}  $options
 */
function runQuoteSeqSync(array $options = [], bool $execute = false): PendingCommand
{
    $parameters = [
        '--confirm-database' => $options['confirm'] ?? quoteSeqSyncDbName(),
    ];

    if ($execute) {
        $parameters['--execute'] = true;
    }

    return test()->artisan('quotes:sync-sequences', $parameters);
}

/**
 * @return array{pelican: Organization, brim: Organization, parent: ParentAccount}
 */
function seedApprovedQuoteSequenceOrgs(): array
{
    $parent = ParentAccount::factory()->create();

    $pelican = Organization::factory()->create([
        'parent_account_id' => $parent->id,
        'name' => 'Pelican Signs',
        'slug' => 'pelican-signs',
    ]);

    $brim = Organization::factory()->create([
        'parent_account_id' => $parent->id,
        'name' => 'Brim Drinkware',
        'slug' => 'brim-drinkware',
    ]);

    NumberSequence::query()->create([
        'organization_id' => $pelican->id,
        'sequence_key' => NumberSequenceAllocator::KEY_CUSTOMER,
        'prefix' => 'PEL-C-',
        'next_number' => 1,
        'pad_length' => 5,
    ]);
    NumberSequence::query()->create([
        'organization_id' => $pelican->id,
        'sequence_key' => NumberSequenceAllocator::KEY_DEAL,
        'prefix' => 'PEL-D-',
        'next_number' => 1,
        'pad_length' => 5,
    ]);
    NumberSequence::query()->create([
        'organization_id' => $brim->id,
        'sequence_key' => NumberSequenceAllocator::KEY_CUSTOMER,
        'prefix' => 'BRIM-C-',
        'next_number' => 1,
        'pad_length' => 5,
    ]);
    NumberSequence::query()->create([
        'organization_id' => $brim->id,
        'sequence_key' => NumberSequenceAllocator::KEY_DEAL,
        'prefix' => 'BRIM-D-',
        'next_number' => 1,
        'pad_length' => 5,
    ]);

    return ['pelican' => $pelican, 'brim' => $brim, 'parent' => $parent];
}

/**
 * @return list<array{organization_id: int, sequence_key: string, prefix: string, next_number: int, pad_length: int}>
 */
function nonQuoteSequenceSnapshot(): array
{
    return NumberSequence::query()
        ->where('sequence_key', '!=', NumberSequenceAllocator::KEY_QUOTE)
        ->orderBy('id')
        ->get(['organization_id', 'sequence_key', 'prefix', 'next_number', 'pad_length'])
        ->map(fn (NumberSequence $row): array => [
            'organization_id' => $row->organization_id,
            'sequence_key' => $row->sequence_key,
            'prefix' => $row->prefix,
            'next_number' => $row->next_number,
            'pad_length' => $row->pad_length,
        ])
        ->all();
}

test('dry-run proposes exactly two quote sequences and makes no writes', function () {
    $orgs = seedApprovedQuoteSequenceOrgs();

    expect(NumberSequence::query()->count())->toBe(4)
        ->and(NumberSequence::query()->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)->count())->toBe(0);

    $before = nonQuoteSequenceSnapshot();
    $plan = app(QuoteNumberSequenceSynchronizer::class)->buildPlan();

    expect($plan['sequences_to_create'])->toHaveCount(2)
        ->and(collect($plan['sequences_to_create'])->pluck('organization_slug')->all())->toBe(['pelican-signs', 'brim-drinkware'])
        ->and(collect($plan['sequences_to_create'])->pluck('prefix')->all())->toBe(['PEL-Q-', 'BRIM-Q-'])
        ->and(collect($plan['sequences_to_create'])->pluck('pad_length')->unique()->all())->toBe([5])
        ->and(collect($plan['sequences_to_create'])->pluck('next_number')->unique()->all())->toBe([1])
        ->and($plan['sequences_to_create'][0]['organization_id'])->toBe($orgs['pelican']->id)
        ->and($plan['sequences_to_create'][1]['organization_id'])->toBe($orgs['brim']->id)
        ->and($plan['unchanged_sequences'])->toBe([])
        ->and($plan['conflicts'])->toBe([])
        ->and($plan['missing_organizations'])->toBe([])
        ->and($plan['unrelated_sequence_count'])->toBe(4);

    runQuoteSeqSync()
        ->expectsOutputToContain('Sequences to create (2):')
        ->expectsOutputToContain('Unchanged sequences (0):')
        ->expectsOutputToContain('Blocking conflicts (0):')
        ->expectsOutputToContain('Missing organizations (0):')
        ->expectsOutputToContain('Unrelated sequences left untouched: 4')
        ->expectsOutputToContain('No writes were performed')
        ->assertSuccessful();

    expect(NumberSequence::query()->count())->toBe(4)
        ->and(NumberSequence::query()->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)->count())->toBe(0)
        ->and(nonQuoteSequenceSnapshot())->toBe($before);
});

test('execute requires database confirmation and explicit flag', function () {
    seedApprovedQuoteSequenceOrgs();

    test()->artisan('quotes:sync-sequences', [
        '--execute' => true,
    ])
        ->expectsOutputToContain('Exact database confirmation is required')
        ->assertFailed();

    test()->artisan('quotes:sync-sequences', [
        '--confirm-database' => quoteSeqSyncDbName(),
    ])
        ->expectsOutputToContain('No writes were performed')
        ->assertSuccessful();

    expect(NumberSequence::query()->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)->count())->toBe(0);
});

test('wrong database confirmation is rejected before writes', function () {
    seedApprovedQuoteSequenceOrgs();

    runQuoteSeqSync(['confirm' => 'wrong-database-name'], execute: true)
        ->expectsOutputToContain('does not match active database')
        ->assertFailed();

    expect(NumberSequence::query()->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)->count())->toBe(0)
        ->and(NumberSequence::query()->count())->toBe(4);
});

test('execute creates exactly two quote sequences starting at next_number 1 without allocation', function () {
    $orgs = seedApprovedQuoteSequenceOrgs();
    $beforeNonQuote = nonQuoteSequenceSnapshot();

    runQuoteSeqSync(execute: true)
        ->expectsOutputToContain('created')
        ->assertSuccessful();

    $quoteSequences = NumberSequence::query()
        ->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)
        ->orderBy('organization_id')
        ->get();

    expect(NumberSequence::query()->count())->toBe(6)
        ->and($quoteSequences)->toHaveCount(2)
        ->and(nonQuoteSequenceSnapshot())->toBe($beforeNonQuote);

    $byOrg = $quoteSequences->keyBy('organization_id');

    expect($byOrg[$orgs['pelican']->id]->prefix)->toBe('PEL-Q-')
        ->and($byOrg[$orgs['pelican']->id]->pad_length)->toBe(5)
        ->and($byOrg[$orgs['pelican']->id]->next_number)->toBe(1)
        ->and($byOrg[$orgs['brim']->id]->prefix)->toBe('BRIM-Q-')
        ->and($byOrg[$orgs['brim']->id]->pad_length)->toBe(5)
        ->and($byOrg[$orgs['brim']->id]->next_number)->toBe(1);
});

test('second dry-run has zero delta and second execute is idempotent', function () {
    seedApprovedQuoteSequenceOrgs();
    runQuoteSeqSync(execute: true)->assertSuccessful();

    $before = [
        'total' => NumberSequence::query()->count(),
        'quote' => NumberSequence::query()->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)->count(),
        'non_quote' => nonQuoteSequenceSnapshot(),
        'quote_rows' => NumberSequence::query()
            ->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)
            ->orderBy('id')
            ->get(['organization_id', 'prefix', 'next_number', 'pad_length'])
            ->toArray(),
    ];

    runQuoteSeqSync()
        ->expectsOutputToContain('Sequences to create (0):')
        ->expectsOutputToContain('Unchanged sequences (2):')
        ->expectsOutputToContain('No quote sequence changes proposed')
        ->assertSuccessful();

    runQuoteSeqSync(execute: true)->assertSuccessful();

    expect(NumberSequence::query()->count())->toBe($before['total'])
        ->and(NumberSequence::query()->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)->count())->toBe($before['quote'])
        ->and(nonQuoteSequenceSnapshot())->toBe($before['non_quote'])
        ->and(NumberSequence::query()
            ->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)
            ->orderBy('id')
            ->get(['organization_id', 'prefix', 'next_number', 'pad_length'])
            ->toArray())->toBe($before['quote_rows']);
});

test('matching existing sequence with advanced next_number is preserved', function () {
    $orgs = seedApprovedQuoteSequenceOrgs();

    NumberSequence::query()->create([
        'organization_id' => $orgs['pelican']->id,
        'sequence_key' => NumberSequenceAllocator::KEY_QUOTE,
        'prefix' => 'PEL-Q-',
        'next_number' => 17,
        'pad_length' => 5,
    ]);

    $plan = app(QuoteNumberSequenceSynchronizer::class)->buildPlan();

    expect($plan['sequences_to_create'])->toHaveCount(1)
        ->and($plan['sequences_to_create'][0]['organization_slug'])->toBe('brim-drinkware')
        ->and($plan['unchanged_sequences'])->toHaveCount(1)
        ->and($plan['unchanged_sequences'][0]['organization_slug'])->toBe('pelican-signs')
        ->and($plan['unchanged_sequences'][0]['next_number'])->toBe(17);

    runQuoteSeqSync()
        ->expectsOutputToContain('Sequences to create (1):')
        ->expectsOutputToContain('Unchanged sequences (1):')
        ->assertSuccessful();

    runQuoteSeqSync(execute: true)->assertSuccessful();

    expect(NumberSequence::query()
        ->where('organization_id', $orgs['pelican']->id)
        ->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)
        ->value('next_number'))->toBe(17)
        ->and(NumberSequence::query()
            ->where('organization_id', $orgs['brim']->id)
            ->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)
            ->value('next_number'))->toBe(1);
});

test('prefix conflict blocks all writes', function () {
    $orgs = seedApprovedQuoteSequenceOrgs();

    NumberSequence::query()->create([
        'organization_id' => $orgs['pelican']->id,
        'sequence_key' => NumberSequenceAllocator::KEY_QUOTE,
        'prefix' => 'WRONG-Q-',
        'next_number' => 1,
        'pad_length' => 5,
    ]);

    $before = NumberSequence::query()->count();

    $plan = app(QuoteNumberSequenceSynchronizer::class)->buildPlan();

    expect($plan['conflicts'])->toHaveCount(1)
        ->and($plan['conflicts'][0]['detail'])->toContain('expected prefix [PEL-Q-]')
        ->and($plan['conflicts'][0]['detail'])->toContain('actual prefix [WRONG-Q-]')
        ->and($plan['sequences_to_create'])->toHaveCount(1)
        ->and($plan['sequences_to_create'][0]['organization_slug'])->toBe('brim-drinkware');

    runQuoteSeqSync()
        ->expectsOutputToContain('Blocking conflicts (1):')
        ->assertFailed();

    runQuoteSeqSync(execute: true)->assertFailed();

    expect(NumberSequence::query()->count())->toBe($before)
        ->and(NumberSequence::query()
            ->where('organization_id', $orgs['brim']->id)
            ->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)
            ->exists())->toBeFalse();
});

test('padding conflict blocks all writes', function () {
    $orgs = seedApprovedQuoteSequenceOrgs();

    NumberSequence::query()->create([
        'organization_id' => $orgs['brim']->id,
        'sequence_key' => NumberSequenceAllocator::KEY_QUOTE,
        'prefix' => 'BRIM-Q-',
        'next_number' => 1,
        'pad_length' => 4,
    ]);

    $before = NumberSequence::query()->count();
    $plan = app(QuoteNumberSequenceSynchronizer::class)->buildPlan();

    expect($plan['conflicts'])->toHaveCount(1)
        ->and($plan['conflicts'][0]['detail'])->toContain('pad_length [5]')
        ->and($plan['conflicts'][0]['detail'])->toContain('pad_length [4]')
        ->and($plan['sequences_to_create'])->toHaveCount(1)
        ->and($plan['sequences_to_create'][0]['organization_slug'])->toBe('pelican-signs');

    runQuoteSeqSync()
        ->expectsOutputToContain('Blocking conflicts (1):')
        ->assertFailed();

    runQuoteSeqSync(execute: true)->assertFailed();

    expect(NumberSequence::query()->count())->toBe($before)
        ->and(NumberSequence::query()
            ->where('organization_id', $orgs['pelican']->id)
            ->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)
            ->exists())->toBeFalse();
});

test('missing organization blocks all writes including the other sequence', function () {
    $parent = ParentAccount::factory()->create();
    Organization::factory()->create([
        'parent_account_id' => $parent->id,
        'name' => 'Pelican Signs',
        'slug' => 'pelican-signs',
    ]);

    $plan = app(QuoteNumberSequenceSynchronizer::class)->buildPlan();

    expect($plan['missing_organizations'])->toHaveCount(1)
        ->and($plan['missing_organizations'][0]['organization_slug'])->toBe('brim-drinkware')
        ->and($plan['sequences_to_create'])->toHaveCount(1)
        ->and($plan['sequences_to_create'][0]['organization_slug'])->toBe('pelican-signs');

    runQuoteSeqSync()
        ->expectsOutputToContain('Missing organizations (1):')
        ->expectsOutputToContain('Sequences to create (1):')
        ->assertFailed();

    runQuoteSeqSync(execute: true)->assertFailed();

    expect(NumberSequence::query()->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)->count())->toBe(0);
});

test('advisory lock failure blocks writes and leaves lock free', function () {
    seedApprovedQuoteSequenceOrgs();
    app()->instance('quoteSequenceSync.denyLock', true);

    runQuoteSeqSync(execute: true)
        ->expectsOutputToContain('Unable to acquire quote sequence synchronization lock')
        ->assertFailed();

    expect(NumberSequence::query()->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)->count())->toBe(0)
        ->and(app(QuoteNumberSequenceSynchronizer::class)->isLockFree())->toBeTrue();

    app()->instance('quoteSequenceSync.denyLock', false);
});

test('induced failure rolls back all writes and releases lock', function () {
    seedApprovedQuoteSequenceOrgs();
    app()->instance('quoteSequenceSync.induceFailure', true);

    runQuoteSeqSync(execute: true)->assertFailed();

    expect(NumberSequence::query()->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)->count())->toBe(0)
        ->and(NumberSequence::query()->count())->toBe(4)
        ->and(app(QuoteNumberSequenceSynchronizer::class)->isLockFree())->toBeTrue();

    app()->instance('quoteSequenceSync.induceFailure', false);

    runQuoteSeqSync(execute: true)->assertSuccessful();

    expect(NumberSequence::query()->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)->count())->toBe(2)
        ->and(app(QuoteNumberSequenceSynchronizer::class)->isLockFree())->toBeTrue();
});

test('command does not invoke phase zero bootstrap or allocate numbers', function () {
    seedApprovedQuoteSequenceOrgs();

    runQuoteSeqSync(execute: true)->assertSuccessful();

    expect(NumberSequence::query()
        ->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)
        ->where('next_number', '>', 1)
        ->count())->toBe(0)
        ->and(DB::table('audit_events')->where('action', PhaseZeroBootstrap::COMPLETION_ACTION)->count())->toBe(0);
});

test('buildPlan is read-only and reports unrelated sequence count', function () {
    seedApprovedQuoteSequenceOrgs();

    $plan = app(QuoteNumberSequenceSynchronizer::class)->buildPlan();

    expect($plan['sequences_to_create'])->toHaveCount(2)
        ->and($plan['unchanged_sequences'])->toBe([])
        ->and($plan['conflicts'])->toBe([])
        ->and($plan['missing_organizations'])->toBe([])
        ->and($plan['unrelated_sequence_count'])->toBe(4)
        ->and(NumberSequence::query()->count())->toBe(4);
});

test('definitions cover approved organizations only', function () {
    expect(QuoteNumberSequenceDefinitions::byOrganizationSlug())->toBe([
        'pelican-signs' => ['prefix' => 'PEL-Q-', 'pad_length' => 5],
        'brim-drinkware' => ['prefix' => 'BRIM-Q-', 'pad_length' => 5],
    ]);
});
