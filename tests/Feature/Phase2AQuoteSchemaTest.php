<?php

use App\Enums\QuoteRevisionStatus;
use App\Models\Deal;
use App\Models\QuoteStatusEvent;
use Database\Factories\QuoteFactory;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const PHASE_2A_MIGRATIONS = [
    '2026_07_26_212401_create_quotes_table',
    '2026_07_26_212402_create_quote_revisions_table',
    '2026_07_26_212403_create_quote_status_events_table',
    '2026_07_26_212404_add_quote_revision_pointer_foreign_keys',
];

function phase2aHasIndex(string $table, string $indexName, bool $unique = false): bool
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

function phase2aHasForeign(string $table, string $name, array $columns, string $foreignTable): bool
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

function phase2aForeignOnDelete(string $table, array $columns, string $foreignTable): ?string
{
    foreach (Schema::getForeignKeys($table) as $foreign) {
        if (($foreign['columns'] ?? []) !== $columns || ($foreign['foreign_table'] ?? null) !== $foreignTable) {
            continue;
        }

        return strtolower((string) ($foreign['on_delete'] ?? ''));
    }

    return null;
}

function phase2aRollback(): void
{
    if (Schema::getConnection()->getDriverName() === 'sqlite') {
        // SQLite cannot drop named foreign keys from migration 212404.
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('quote_status_events');
        Schema::dropIfExists('quote_revisions');
        Schema::dropIfExists('quotes');
        Schema::enableForeignKeyConstraints();

        if (phase2aHasIndex('deals', 'de_org_id_uidx', unique: true)) {
            Schema::table('deals', function (Blueprint $table): void {
                $table->dropUnique('de_org_id_uidx');
            });
        }

        if (! phase2aHasIndex('deals', 'deals_organization_id_id_index')) {
            Schema::table('deals', function (Blueprint $table): void {
                $table->index(['organization_id', 'id'], 'deals_organization_id_id_index');
            });
        }

        DB::table('migrations')->whereIn('migration', PHASE_2A_MIGRATIONS)->delete();

        return;
    }

    Artisan::call('migrate:rollback', ['--step' => 4, '--force' => true]);
}

function phase2aRemigrate(): void
{
    Artisan::call('migrate', ['--force' => true]);
}

test('phase 2a quote tables exist with expected unique indexes', function () {
    expect(Schema::hasTable('quotes'))->toBeTrue()
        ->and(Schema::hasTable('quote_revisions'))->toBeTrue()
        ->and(Schema::hasTable('quote_status_events'))->toBeTrue()
        ->and(phase2aHasIndex('quotes', 'qu_org_number_uidx', unique: true))->toBeTrue()
        ->and(phase2aHasIndex('quotes', 'qu_org_id_uidx', unique: true))->toBeTrue()
        ->and(phase2aHasIndex('quote_revisions', 'qrev_quote_number_uidx', unique: true))->toBeTrue()
        ->and(phase2aHasIndex('quote_revisions', 'qrev_quote_id_uidx', unique: true))->toBeTrue()
        ->and(phase2aHasIndex('deals', 'de_org_id_uidx', unique: true))->toBeTrue()
        ->and(Schema::hasColumn('quote_status_events', 'updated_at'))->toBeFalse();
});

test('phase 2a quote revision money columns default to zero', function () {
    $quote = QuoteFactory::createForDeal();
    $revision = $quote->currentRevision;

    expect($revision)->not->toBeNull()
        ->and($revision->subtotal_cents)->toBe(0)
        ->and($revision->discount_cents)->toBe(0)
        ->and($revision->taxable_amount_cents)->toBe(0)
        ->and($revision->tax_cents)->toBe(0)
        ->and($revision->grand_total_cents)->toBe(0)
        ->and($revision->currency_code)->toBe('USD');

    $id = DB::table('quote_revisions')->insertGetId([
        'parent_account_id' => $quote->parent_account_id,
        'organization_id' => $quote->organization_id,
        'quote_id' => $quote->id,
        'revision_number' => 99,
        'status' => QuoteRevisionStatus::Draft->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = DB::table('quote_revisions')->where('id', $id)->first();

    expect((int) $row->subtotal_cents)->toBe(0)
        ->and((int) $row->discount_cents)->toBe(0)
        ->and((int) $row->taxable_amount_cents)->toBe(0)
        ->and((int) $row->tax_cents)->toBe(0)
        ->and((int) $row->grand_total_cents)->toBe(0)
        ->and($row->currency_code)->toBe('USD');
});

test('phase 2a quote_number is unique per organization and reusable across orgs', function () {
    $orgA = createTenantUser('salesperson');
    $orgB = createTenantUser('salesperson');

    $dealA = Deal::factory()->create([
        'organization_id' => $orgA['organization']->id,
        'parent_account_id' => $orgA['parent']->id,
        'owner_id' => $orgA['user']->id,
    ]);
    $dealB = Deal::factory()->create([
        'organization_id' => $orgB['organization']->id,
        'parent_account_id' => $orgB['parent']->id,
        'owner_id' => $orgB['user']->id,
    ]);

    $quoteA = QuoteFactory::createForDeal($dealA, $orgA['membership'], 'SHR-Q-', 5);
    $quoteB = QuoteFactory::createForDeal($dealB, $orgB['membership'], 'SHR-Q-', 5);

    expect($quoteA->quote_number)->toBe($quoteB->quote_number);

    expect(fn () => DB::table('quotes')->insert([
        'parent_account_id' => $quoteA->parent_account_id,
        'organization_id' => $quoteA->organization_id,
        'deal_id' => $dealA->id,
        'organization_company_id' => $quoteA->organization_company_id,
        'quote_number' => $quoteA->quote_number,
        'lifecycle_status' => 'open',
        'created_by_membership_id' => $orgA['membership']->id,
        'lock_version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('phase 2a revision_number is unique per quote', function () {
    $quote = QuoteFactory::createForDeal();

    expect(fn () => DB::table('quote_revisions')->insert([
        'parent_account_id' => $quote->parent_account_id,
        'organization_id' => $quote->organization_id,
        'quote_id' => $quote->id,
        'revision_number' => 1,
        'status' => QuoteRevisionStatus::Draft->value,
        'lock_version' => 1,
        'currency_code' => 'USD',
        'subtotal_cents' => 0,
        'discount_cents' => 0,
        'taxable_amount_cents' => 0,
        'tax_cents' => 0,
        'grand_total_cents' => 0,
        'approval_required' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('phase 2a foreign keys use qu_ qrev_ and qse_ names', function () {
    expect(phase2aHasForeign('quotes', 'qu_pa_fk', ['parent_account_id'], 'parent_accounts'))->toBeTrue()
        ->and(phase2aHasForeign('quotes', 'qu_org_fk', ['organization_id'], 'organizations'))->toBeTrue()
        ->and(phase2aHasForeign('quotes', 'qu_pa_org_fk', ['parent_account_id', 'organization_id'], 'organizations'))->toBeTrue()
        ->and(phase2aHasForeign('quotes', 'qu_org_deal_fk', ['organization_id', 'deal_id'], 'deals'))->toBeTrue()
        ->and(phase2aHasForeign('quotes', 'qu_org_oc_fk', ['organization_id', 'organization_company_id'], 'organization_companies'))->toBeTrue()
        ->and(phase2aHasForeign('quotes', 'qu_org_created_mem_fk', ['organization_id', 'created_by_membership_id'], 'memberships'))->toBeTrue()
        ->and(phase2aHasForeign('quote_revisions', 'qrev_pa_fk', ['parent_account_id'], 'parent_accounts'))->toBeTrue()
        ->and(phase2aHasForeign('quote_revisions', 'qrev_org_quote_fk', ['organization_id', 'quote_id'], 'quotes'))->toBeTrue()
        ->and(phase2aHasForeign('quote_status_events', 'qse_pa_fk', ['parent_account_id'], 'parent_accounts'))->toBeTrue()
        ->and(phase2aHasForeign('quote_status_events', 'qse_org_quote_fk', ['organization_id', 'quote_id'], 'quotes'))->toBeTrue()
        ->and(phase2aHasForeign('quote_status_events', 'qse_quote_revision_fk', ['quote_id', 'quote_revision_id'], 'quote_revisions'))->toBeTrue();
});

test('phase 2a deal delete is restricted when a quote exists', function () {
    $onDelete = phase2aForeignOnDelete('quotes', ['organization_id', 'deal_id'], 'deals');

    expect(in_array($onDelete, ['restrict', 'no action'], true))->toBeTrue();

    $quote = QuoteFactory::createForDeal();
    $deal = Deal::query()->findOrFail($quote->deal_id);

    if (Schema::getConnection()->getDriverName() === 'mysql') {
        expect(fn () => DB::table('deals')->where('id', $deal->id)->delete())
            ->toThrow(QueryException::class);
    }
});

test('phase 2a quote pointer foreign keys enforce same-quote revisions', function () {
    expect(phase2aHasForeign('quotes', 'qu_current_rev_fk', ['id', 'current_revision_id'], 'quote_revisions'))->toBeTrue()
        ->and(phase2aHasForeign('quotes', 'qu_accepted_rev_fk', ['id', 'accepted_revision_id'], 'quote_revisions'))->toBeTrue();

    $quoteA = QuoteFactory::createForDeal();
    $quoteB = QuoteFactory::createForDeal();

    expect(fn () => DB::table('quotes')->where('id', $quoteA->id)->update([
        'current_revision_id' => $quoteB->current_revision_id,
    ]))->toThrow(QueryException::class);
});

test('phase 2a quote status events are append-only', function () {
    $quote = QuoteFactory::createForDeal();
    $event = QuoteStatusEvent::query()->where('quote_id', $quote->id)->firstOrFail();

    expect(fn () => $event->update(['to_status' => QuoteRevisionStatus::Approved->value]))
        ->toThrow(LogicException::class, 'append-only');

    expect(fn () => $event->delete())
        ->toThrow(LogicException::class, 'append-only');

    expect(QuoteStatusEvent::query()->whereKey($event->id)->exists())->toBeTrue();
});

test('phase 2a rollback step 4 removes quote schema and remigrate restores it', function () {
    expect(Schema::hasTable('quotes'))->toBeTrue()
        ->and(Schema::hasTable('quote_revisions'))->toBeTrue()
        ->and(Schema::hasTable('quote_status_events'))->toBeTrue()
        ->and(phase2aHasIndex('deals', 'de_org_id_uidx', unique: true))->toBeTrue()
        ->and(DB::table('migrations')->whereIn('migration', PHASE_2A_MIGRATIONS)->count())->toBe(4);

    phase2aRollback();

    expect(Schema::hasTable('quotes'))->toBeFalse()
        ->and(Schema::hasTable('quote_revisions'))->toBeFalse()
        ->and(Schema::hasTable('quote_status_events'))->toBeFalse()
        ->and(phase2aHasIndex('deals', 'de_org_id_uidx', unique: true))->toBeFalse()
        ->and(DB::table('migrations')->whereIn('migration', PHASE_2A_MIGRATIONS)->count())->toBe(0);

    phase2aRemigrate();

    expect(Schema::hasTable('quotes'))->toBeTrue()
        ->and(Schema::hasTable('quote_revisions'))->toBeTrue()
        ->and(Schema::hasTable('quote_status_events'))->toBeTrue()
        ->and(phase2aHasIndex('deals', 'de_org_id_uidx', unique: true))->toBeTrue()
        ->and(phase2aHasForeign('quotes', 'qu_current_rev_fk', ['id', 'current_revision_id'], 'quote_revisions'))->toBeTrue()
        ->and(DB::table('migrations')->whereIn('migration', PHASE_2A_MIGRATIONS)->count())->toBe(4);
});
