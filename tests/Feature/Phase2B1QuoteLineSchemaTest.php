<?php

use App\Enums\QuoteLineType;
use App\Enums\QuoteRevisionStatus;
use App\Enums\QuoteTaxCalculationStatus;
use App\Models\AuditEvent;
use App\Models\NumberSequence;
use App\Models\Organization;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionAdjustment;
use App\Models\QuoteRevisionLineItem;
use App\Models\QuoteRevisionPartySnapshot;
use App\Support\Quotes\ImmutableQuoteRevisionException;
use App\Support\Quotes\Totals\QuoteTotalsCalculator;
use Database\Factories\QuoteFactory;
use Database\Factories\QuoteRevisionLineItemFactory;
use Database\Factories\QuoteRevisionPartySnapshotFactory;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const PHASE_2B1_MIGRATIONS = [
    '2026_07_27_020721_create_quote_revision_party_snapshots_table',
    '2026_07_27_020722_create_quote_revision_line_items_table',
    '2026_07_27_020723_create_quote_revision_adjustments_table',
    '2026_07_27_020724_add_tax_readiness_columns_to_quote_revisions_table',
];

function phase2b1HasIndex(string $table, string $indexName, bool $unique = false): bool
{
    foreach (Schema::getIndexes($table) as $index) {
        if (($index['name'] ?? null) !== $indexName) {
            continue;
        }

        if ($unique && ! ($index['unique'] ?? false)) {
            return false;
        }

        return true;
    }

    return false;
}

function phase2b1HasForeign(string $table, string $name, array $columns, string $foreignTable): bool
{
    $driver = Schema::getConnection()->getDriverName();

    foreach (Schema::getForeignKeys($table) as $foreign) {
        if (($foreign['columns'] ?? []) !== $columns || ($foreign['foreign_table'] ?? null) !== $foreignTable) {
            continue;
        }

        if ($driver === 'mysql') {
            return ($foreign['name'] ?? null) === $name;
        }

        return true;
    }

    return false;
}

function phase2b1ForeignOnDelete(string $table, array $columns, string $foreignTable): ?string
{
    foreach (Schema::getForeignKeys($table) as $foreign) {
        if (($foreign['columns'] ?? []) !== $columns || ($foreign['foreign_table'] ?? null) !== $foreignTable) {
            continue;
        }

        return strtolower((string) ($foreign['on_delete'] ?? ''));
    }

    return null;
}

function phase2b1Rollback(): void
{
    if (Schema::getConnection()->getDriverName() === 'sqlite') {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('quote_revision_adjustments');
        Schema::dropIfExists('quote_revision_line_items');
        Schema::dropIfExists('quote_revision_party_snapshots');
        if (Schema::hasColumn('quote_revisions', 'tax_calculation_status')) {
            Schema::table('quote_revisions', function (Blueprint $table): void {
                $table->dropColumn(['tax_calculation_status', 'tax_snapshot_json', 'tax_calculated_at']);
            });
        }
        Schema::enableForeignKeyConstraints();
        DB::table('migrations')->whereIn('migration', PHASE_2B1_MIGRATIONS)->delete();

        return;
    }

    // Phase 2E.1 (1) + Phase 2D.1 (6) + Phase 2C.1 (6) + Phase 2B.1 (4).
    Artisan::call('migrate:rollback', ['--step' => 17, '--force' => true]);
}

test('phase 2b1 tables indexes and tax readiness columns exist', function () {
    expect(Schema::hasTable('quote_revision_party_snapshots'))->toBeTrue()
        ->and(Schema::hasTable('quote_revision_line_items'))->toBeTrue()
        ->and(Schema::hasTable('quote_revision_adjustments'))->toBeTrue()
        ->and(Schema::hasColumns('quote_revisions', [
            'tax_calculation_status',
            'tax_snapshot_json',
            'tax_calculated_at',
        ]))->toBeTrue()
        ->and(phase2b1HasIndex('quote_revision_party_snapshots', 'qrps_revision_uidx', unique: true))->toBeTrue()
        ->and(phase2b1HasIndex('quote_revision_line_items', 'qrli_rev_pos_uidx', unique: true))->toBeTrue()
        ->and(phase2b1HasIndex('quote_revision_adjustments', 'qradj_rev_pos_uidx', unique: true))->toBeTrue()
        ->and(phase2b1HasIndex('contacts', 'ct_pa_id_uidx', unique: true))->toBeTrue();
});

test('phase 2b1 composite FKs are tenant-safe and restrict deletes', function () {
    expect(phase2b1HasForeign('quote_revision_line_items', 'qrli_quote_rev_fk', ['quote_id', 'quote_revision_id'], 'quote_revisions'))->toBeTrue()
        ->and(phase2b1ForeignOnDelete('quote_revision_line_items', ['quote_id', 'quote_revision_id'], 'quote_revisions'))->toBe('restrict')
        ->and(phase2b1HasForeign('quote_revision_party_snapshots', 'qrps_quote_rev_fk', ['quote_id', 'quote_revision_id'], 'quote_revisions'))->toBeTrue()
        ->and(phase2b1ForeignOnDelete('quote_revision_adjustments', ['organization_id', 'quote_id'], 'quotes'))->toBe('restrict');
});

test('phase 2b1 party snapshot is one per revision and tax defaults pending', function () {
    $quote = QuoteFactory::createForDeal();
    $revision = $quote->currentRevision;
    expect($revision->tax_calculation_status)->toBe(QuoteTaxCalculationStatus::Pending);

    $snapshot = $revision->partySnapshot;
    expect($snapshot)->not->toBeNull()
        ->and($snapshot->quote_revision_id)->toBe($revision->id)
        ->and($revision->fresh()->partySnapshot?->id)->toBe($snapshot->id);

    expect(fn () => QuoteRevisionPartySnapshotFactory::createForRevision($revision))
        ->toThrow(QueryException::class);
});

test('phase 2b1 line and adjustment tenant isolation and position uniqueness', function () {
    $quote = QuoteFactory::createForDeal();
    $revision = $quote->currentRevision;

    $line = QuoteRevisionLineItem::factory()->create([
        'quote_revision_id' => $revision->id,
        'position' => 1,
        'line_type' => QuoteLineType::Custom,
    ]);

    expect($line->organization_id)->toBe($revision->organization_id)
        ->and($line->quote_id)->toBe($revision->quote_id);

    expect(fn () => QuoteRevisionLineItem::factory()->create([
        'quote_revision_id' => $revision->id,
        'position' => 1,
    ]))->toThrow(QueryException::class);

    $otherOrg = Organization::factory()->create();
    expect(fn () => QuoteRevisionLineItem::factory()->create([
        'quote_revision_id' => $revision->id,
        'position' => 2,
        'organization_id' => $otherOrg->id,
        'parent_account_id' => $otherOrg->parent_account_id,
        'quote_id' => $revision->quote_id,
    ]))->toThrow(QueryException::class);
});

test('phase 2b1 sent revision children are immutable', function () {
    $quote = QuoteFactory::createForDeal();
    $revision = $quote->currentRevision;
    $line = QuoteRevisionLineItem::factory()->create([
        'quote_revision_id' => $revision->id,
        'position' => 1,
    ]);
    $adjustment = QuoteRevisionAdjustment::factory()->create([
        'quote_revision_id' => $revision->id,
        'position' => 1,
    ]);

    QuoteRevision::$allowLifecycleMutation = true;
    $revision->forceFill([
        'status' => QuoteRevisionStatus::Sent,
        'sent_at' => now(),
        'lock_version' => $revision->lock_version + 1,
    ])->save();
    QuoteRevision::$allowLifecycleMutation = false;

    expect(fn () => $line->fresh()->update(['name_snapshot' => 'Changed']))
        ->toThrow(ImmutableQuoteRevisionException::class);

    expect(fn () => $adjustment->fresh()->update(['description_snapshot' => 'Changed']))
        ->toThrow(ImmutableQuoteRevisionException::class);

    expect(fn () => QuoteRevisionLineItem::factory()->create([
        'quote_revision_id' => $revision->id,
        'position' => 99,
    ]))->toThrow(ImmutableQuoteRevisionException::class);

    expect(fn () => $line->fresh()->delete())
        ->toThrow(ImmutableQuoteRevisionException::class);

    expect(fn () => QuoteRevisionPartySnapshot::query()->whereKey(
        $revision->partySnapshot->id
    )->firstOrFail()->update(['customer_company_name' => 'Hacked']))
        ->toThrow(ImmutableQuoteRevisionException::class);
});

test('phase 2b1 section note factories store zero money', function () {
    $quote = QuoteFactory::createForDeal();
    $revision = $quote->currentRevision;

    $section = QuoteRevisionLineItemFactory::new()->section()->create([
        'quote_revision_id' => $revision->id,
        'position' => 1,
        'name_snapshot' => 'Materials',
    ]);
    $note = QuoteRevisionLineItemFactory::new()->note()->create([
        'quote_revision_id' => $revision->id,
        'position' => 2,
        'name_snapshot' => 'Lead time note',
    ]);

    expect($section->net_line_total_cents)->toBe(0)
        ->and($note->extended_price_cents)->toBe(0)
        ->and($section->line_type)->toBe(QuoteLineType::Section);
});

test('phase 2b1 pure calculation has no audit or sequence side effects', function () {
    $auditsBefore = AuditEvent::query()->count();
    $seqBefore = NumberSequence::query()->orderBy('id')->get()->toArray();

    $quote = QuoteFactory::createForDeal();
    // FactoryService allocates a quote number — capture after that for calculation purity.
    $auditsAfterCreate = AuditEvent::query()->count();
    $seqAfterCreate = NumberSequence::query()->orderBy('id')->get()->toArray();

    app(QuoteTotalsCalculator::class)->calculate([]);

    expect(AuditEvent::query()->count())->toBe($auditsAfterCreate)
        ->and(NumberSequence::query()->orderBy('id')->get()->toArray())->toBe($seqAfterCreate)
        ->and($auditsAfterCreate)->toBeGreaterThanOrEqual($auditsBefore)
        ->and($seqAfterCreate)->not->toBe($seqBefore); // create allocates; calculator must not allocate further
});

test('phase 2b1 rollback remigrates without losing phase 2a tables', function () {
    expect(Schema::hasTable('quotes'))->toBeTrue()
        ->and(Schema::hasTable('quote_revision_line_items'))->toBeTrue();

    phase2b1Rollback();

    expect(Schema::hasTable('quote_revision_line_items'))->toBeFalse()
        ->and(Schema::hasTable('quote_revision_adjustments'))->toBeFalse()
        ->and(Schema::hasTable('quote_revision_party_snapshots'))->toBeFalse()
        ->and(Schema::hasTable('quotes'))->toBeTrue()
        ->and(Schema::hasTable('quote_revisions'))->toBeTrue()
        ->and(Schema::hasColumn('quote_revisions', 'tax_calculation_status'))->toBeFalse();

    Artisan::call('migrate', ['--force' => true]);

    expect(Schema::hasTable('quote_revision_line_items'))->toBeTrue()
        ->and(Schema::hasColumn('quote_revisions', 'tax_calculation_status'))->toBeTrue();
});
