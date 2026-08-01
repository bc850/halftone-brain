<?php

use App\Enums\QuoteCustomerResponseSource;
use App\Enums\QuoteCustomerResponseType;
use App\Enums\QuoteDeliveryStatus;
use App\Enums\QuoteDocumentGenerationStatus;
use App\Models\IntegrationOutbox;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\QuoteCustomerAccessToken;
use App\Models\QuoteCustomerResponseEvent;
use App\Models\QuoteDelivery;
use App\Models\QuoteDeliveryEvent;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionDocument;
use App\Support\Quotes\Acceptance\QuoteAcceptanceAtomicityContract;
use App\Support\Quotes\Documents\CustomerQuoteDocumentIntegrity;
use App\Support\Quotes\Security\QuoteCustomerAccessTokenGenerator;
use App\Support\Quotes\Snapshots\CustomerSafeQuoteProjection;
use App\Support\Tenancy\RbacDefinitions;
use Database\Factories\QuoteFactory;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const PHASE_2D1_MIGRATIONS = [
    '2026_07_28_140524_create_quote_revision_documents_table',
    '2026_07_28_140525_create_quote_deliveries_table',
    '2026_07_28_140526_create_quote_delivery_events_table',
    '2026_07_28_140527_create_quote_customer_access_tokens_table',
    '2026_07_28_140528_create_quote_customer_response_events_table',
    '2026_07_28_140529_create_integration_outbox_table',
];

const PHASE_2D1_TABLES = [
    'quote_revision_documents',
    'quote_deliveries',
    'quote_delivery_events',
    'quote_customer_access_tokens',
    'quote_customer_response_events',
    'integration_outbox',
];

function phase2d1HasIndex(string $table, string $indexName, bool $unique = false): bool
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

function phase2d1HasForeign(string $table, string $name, array $columns, string $foreignTable): bool
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

function phase2d1ForeignOnDelete(string $table, array $columns, string $foreignTable): ?string
{
    foreach (Schema::getForeignKeys($table) as $foreign) {
        if (($foreign['columns'] ?? []) !== $columns || ($foreign['foreign_table'] ?? null) !== $foreignTable) {
            continue;
        }

        return strtolower((string) ($foreign['on_delete'] ?? ''));
    }

    return null;
}

function phase2d1Rollback(): void
{
    if (Schema::getConnection()->getDriverName() === 'sqlite') {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('integration_outbox');
        Schema::dropIfExists('quote_customer_response_events');
        Schema::dropIfExists('quote_customer_access_tokens');
        Schema::dropIfExists('quote_delivery_events');
        Schema::dropIfExists('quote_deliveries');
        Schema::dropIfExists('quote_revision_documents');
        phase2d1DropRevisionPointerColumn();
        Schema::enableForeignKeyConstraints();
        DB::table('migrations')->whereIn('migration', PHASE_2D1_MIGRATIONS)->delete();

        return;
    }

    Artisan::call('migrate:rollback', ['--step' => 9, '--force' => true]);
}

/**
 * SQLite refuses to drop a column that a foreign key names, so the pointer column
 * comes off by rebuilding quote_revisions without re-creating later phase tables.
 */
function phase2d1DropRevisionPointerColumn(): void
{
    $keptColumns = array_flip(array_diff(
        Schema::getColumnListing('quote_revisions'),
        ['current_document_id'],
    ));

    $rows = DB::table('quote_revisions')->get()->map(
        fn (object $row): array => array_intersect_key((array) $row, $keptColumns)
    )->all();

    Schema::drop('quote_revisions');

    (require database_path('migrations/2026_07_26_212402_create_quote_revisions_table.php'))->up();
    (require database_path('migrations/2026_07_27_020724_add_tax_readiness_columns_to_quote_revisions_table.php'))->up();

    Schema::table('quote_revisions', function (Blueprint $table): void {
        $table->unsignedBigInteger('current_tax_calculation_id')->nullable()->after('tax_calculated_at');
        $table->unsignedBigInteger('current_approval_request_id')->nullable()->after('current_tax_calculation_id');
    });

    if (Schema::hasTable('quote_revision_tax_calculations')) {
        Schema::table('quote_revisions', function (Blueprint $table): void {
            $table->foreign(['id', 'current_tax_calculation_id'], 'qrev_current_tax_calc_fk')
                ->references(['quote_revision_id', 'id'])
                ->on('quote_revision_tax_calculations')
                ->restrictOnDelete();
        });
    }

    if (Schema::hasTable('quote_approval_requests')) {
        Schema::table('quote_revisions', function (Blueprint $table): void {
            $table->foreign(['id', 'current_approval_request_id'], 'qrev_current_approval_req_fk')
                ->references(['quote_revision_id', 'id'])
                ->on('quote_approval_requests')
                ->restrictOnDelete();
        });
    }

    foreach (array_chunk($rows, 50) as $chunk) {
        DB::table('quote_revisions')->insert($chunk);
    }
}

test('phase 2d1 tables exist with expected unique indexes and append-only shape', function () {
    foreach (PHASE_2D1_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    expect(phase2d1HasIndex('quote_revision_documents', 'qrd_rev_type_version_uidx', unique: true))->toBeTrue()
        ->and(phase2d1HasIndex('quote_revision_documents', 'qrd_rev_id_uidx', unique: true))->toBeTrue()
        ->and(phase2d1HasIndex('quote_deliveries', 'qd_idempotency_uidx', unique: true))->toBeTrue()
        ->and(phase2d1HasIndex('quote_customer_access_tokens', 'qcat_token_hash_uidx', unique: true))->toBeTrue()
        ->and(phase2d1HasIndex('quote_customer_response_events', 'qcre_revision_uidx', unique: true))->toBeTrue()
        ->and(phase2d1HasIndex('integration_outbox', 'iob_idempotency_uidx', unique: true))->toBeTrue()
        ->and(Schema::hasColumn('quote_revision_documents', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('quote_delivery_events', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('quote_customer_response_events', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('quote_revisions', 'current_document_id'))->toBeTrue();
});

test('phase 2d1 composite FKs are tenant-safe and restrict deletes', function () {
    expect(phase2d1HasForeign('quote_revision_documents', 'qrd_quote_rev_fk', ['quote_id', 'quote_revision_id'], 'quote_revisions'))->toBeTrue()
        ->and(phase2d1HasForeign('quote_deliveries', 'qd_rev_document_fk', ['quote_revision_id', 'quote_revision_document_id'], 'quote_revision_documents'))->toBeTrue()
        ->and(phase2d1HasForeign('quote_customer_access_tokens', 'qcat_rev_document_fk', ['quote_revision_id', 'quote_revision_document_id'], 'quote_revision_documents'))->toBeTrue()
        ->and(phase2d1HasForeign('quote_customer_response_events', 'qcre_rev_document_fk', ['quote_revision_id', 'quote_revision_document_id'], 'quote_revision_documents'))->toBeTrue()
        ->and(phase2d1ForeignOnDelete('quote_revision_documents', ['quote_id', 'quote_revision_id'], 'quote_revisions'))->toBe('restrict')
        ->and(phase2d1ForeignOnDelete('quote_deliveries', ['quote_revision_id', 'quote_revision_document_id'], 'quote_revision_documents'))->toBe('restrict')
        ->and(phase2d1ForeignOnDelete('integration_outbox', ['parent_account_id', 'organization_id'], 'organizations'))->toBe('restrict');
});

test('phase 2d1 revision document pointer foreign keys enforce same-revision rows', function () {
    expect(phase2d1HasForeign('quote_revisions', 'qrev_current_document_fk', ['id', 'current_document_id'], 'quote_revision_documents'))->toBeTrue();

    $quoteA = QuoteFactory::createForDeal();
    $quoteB = QuoteFactory::createForDeal();

    $documentForB = QuoteRevisionDocument::factory()->create([
        'quote_revision_id' => $quoteB->current_revision_id,
    ]);

    expect(fn () => DB::table('quote_revisions')->where('id', $quoteA->current_revision_id)->update([
        'current_document_id' => $documentForB->id,
    ]))->toThrow(QueryException::class);

    $ownDocument = QuoteRevisionDocument::factory()->create([
        'quote_revision_id' => $quoteA->current_revision_id,
    ]);

    QuoteRevision::$allowLifecycleMutation = true;
    $quoteA->currentRevision->forceFill(['current_document_id' => $ownDocument->id])->save();
    QuoteRevision::$allowLifecycleMutation = false;

    expect($quoteA->currentRevision->fresh()->currentDocument?->id)->toBe($ownDocument->id);
});

test('phase 2d1 documents are immutable and uniquely versioned per type', function () {
    $quote = QuoteFactory::createForDeal();

    $first = QuoteRevisionDocument::factory()->create([
        'quote_revision_id' => $quote->current_revision_id,
        'document_version' => 1,
    ]);

    expect(fn () => $first->update(['generation_status' => QuoteDocumentGenerationStatus::Failed]))
        ->toThrow(LogicException::class, 'immutable');

    expect(fn () => $first->delete())
        ->toThrow(LogicException::class, 'immutable');

    expect(fn () => QuoteRevisionDocument::factory()->create([
        'quote_revision_id' => $quote->current_revision_id,
        'document_version' => 1,
    ]))->toThrow(QueryException::class);

    $second = QuoteRevisionDocument::factory()->pending()->create([
        'quote_revision_id' => $quote->current_revision_id,
        'document_version' => 2,
    ]);

    expect($quote->currentRevision->documents->pluck('id')->all())
        ->toBe([$first->id, $second->id]);
});

test('phase 2d1 delivery status is not mass-assignable and pending does not mark sent', function () {
    $quote = QuoteFactory::createForDeal();
    $document = QuoteRevisionDocument::factory()->create([
        'quote_revision_id' => $quote->current_revision_id,
    ]);

    $delivery = QuoteDelivery::factory()->create([
        'quote_revision_document_id' => $document->id,
    ]);

    expect($delivery->status)->toBe(QuoteDeliveryStatus::Pending)
        ->and($delivery->status->marksRevisionSent())->toBeFalse()
        ->and(QuoteDeliveryStatus::ProviderAccepted->marksRevisionSent())->toBeTrue()
        ->and(QuoteDeliveryStatus::ManuallyRecorded->marksRevisionSent())->toBeTrue();

    $delivery->fill(['status' => QuoteDeliveryStatus::ProviderAccepted->value]);
    expect($delivery->isDirty('status'))->toBeFalse();

    $delivery->forceFill(['status' => QuoteDeliveryStatus::ProviderAccepted])->save();
    expect($delivery->fresh()->status)->toBe(QuoteDeliveryStatus::ProviderAccepted)
        ->and($delivery->fresh()->status->marksRevisionSent())->toBeTrue();

    expect(fn () => QuoteDelivery::factory()->create([
        'quote_revision_document_id' => $document->id,
        'idempotency_key' => $delivery->idempotency_key,
    ]))->toThrow(QueryException::class);
});

test('phase 2d1 delivery events are append-only', function () {
    $quote = QuoteFactory::createForDeal();
    $document = QuoteRevisionDocument::factory()->create([
        'quote_revision_id' => $quote->current_revision_id,
    ]);
    $delivery = QuoteDelivery::factory()->create([
        'quote_revision_document_id' => $document->id,
    ]);

    $event = QuoteDeliveryEvent::factory()->create([
        'quote_delivery_id' => $delivery->id,
        'to_status' => QuoteDeliveryStatus::Pending,
    ]);

    expect(fn () => $event->update(['to_status' => QuoteDeliveryStatus::Failed]))
        ->toThrow(LogicException::class, 'append-only');

    expect(fn () => $event->delete())
        ->toThrow(LogicException::class, 'append-only');
});

test('phase 2d1 customer access tokens store only sha256 hashes', function () {
    $generator = new QuoteCustomerAccessTokenGenerator;
    $raw = $generator->generateRaw();
    $hash = $generator->hashToken($raw);
    $quote = QuoteFactory::createForDeal();
    $document = QuoteRevisionDocument::factory()->create([
        'quote_revision_id' => $quote->current_revision_id,
    ]);

    $token = QuoteCustomerAccessToken::factory()->create([
        'quote_revision_document_id' => $document->id,
        'token_hash' => $hash,
    ]);

    expect($token->token_hash)->toBe($hash)
        ->and(strlen($token->token_hash))->toBe(64)
        ->and($token->token_hash)->not->toBe($raw)
        ->and(DB::table('quote_customer_access_tokens')->where('id', $token->id)->value('token_hash'))->toBe($hash)
        ->and($generator->verify($raw, $token->token_hash))->toBeTrue()
        ->and($generator->verify('wrong-token', $token->token_hash))->toBeFalse()
        ->and($token->isUsable())->toBeTrue();

    expect(fn () => QuoteCustomerAccessToken::factory()->create([
        'quote_revision_document_id' => $document->id,
        'token_hash' => $hash,
    ]))->toThrow(QueryException::class);
});

test('phase 2d1 customer responses are append-only unique and require terms for acceptance', function () {
    $quote = QuoteFactory::createForDeal();
    $document = QuoteRevisionDocument::factory()->create([
        'quote_revision_id' => $quote->current_revision_id,
    ]);

    expect(fn () => QuoteCustomerResponseEvent::factory()->create([
        'quote_revision_document_id' => $document->id,
        'response' => QuoteCustomerResponseType::Accepted,
        'terms_accepted' => false,
        'typed_name_snapshot' => 'Jamie Customer',
    ]))->toThrow(LogicException::class, 'terms acceptance');

    expect(fn () => QuoteCustomerResponseEvent::factory()->create([
        'quote_revision_document_id' => $document->id,
        'response' => QuoteCustomerResponseType::Accepted,
        'terms_accepted' => true,
        'typed_name_snapshot' => '   ',
    ]))->toThrow(LogicException::class, 'typed name');

    $response = QuoteCustomerResponseEvent::factory()->create([
        'quote_revision_document_id' => $document->id,
        'ip_address_encrypted' => '203.0.113.10',
        'user_agent' => 'Mozilla/5.0 test-agent',
    ]);

    expect($response->response)->toBe(QuoteCustomerResponseType::Accepted)
        ->and($response->source)->toBe(QuoteCustomerResponseSource::Customer)
        ->and($response->ip_address_encrypted)->toBe('203.0.113.10');

    $rawIp = DB::table('quote_customer_response_events')->where('id', $response->id)->value('ip_address_encrypted');
    expect($rawIp)->not->toBe('203.0.113.10')
        ->and($rawIp)->not->toBeNull();

    expect(fn () => $response->update(['rejection_reason' => 'changed']))
        ->toThrow(LogicException::class, 'append-only');

    expect(fn () => $response->delete())
        ->toThrow(LogicException::class, 'append-only');

    expect(fn () => QuoteCustomerResponseEvent::factory()->rejected()->create([
        'quote_revision_document_id' => $document->id,
    ]))->toThrow(QueryException::class);
});

test('phase 2d1 employee-recorded responses require actor and encrypted evidence', function () {
    $quote = QuoteFactory::createForDeal();
    $document = QuoteRevisionDocument::factory()->create([
        'quote_revision_id' => $quote->current_revision_id,
    ]);
    $membership = $quote->createdByMembership()->firstOrFail();

    expect(fn () => QuoteCustomerResponseEvent::factory()->create([
        'quote_revision_document_id' => $document->id,
        'source' => QuoteCustomerResponseSource::Employee,
        'employee_membership_id' => null,
        'employee_user_id' => null,
        'employee_recorded_reason' => 'phone confirmation',
    ]))->toThrow(LogicException::class, 'authorized employee actor');

    expect(fn () => QuoteCustomerResponseEvent::factory()->create([
        'quote_revision_document_id' => $document->id,
        'source' => QuoteCustomerResponseSource::Employee,
        'employee_membership_id' => $membership->id,
        'employee_user_id' => $membership->user_id,
        'employee_recorded_reason' => '   ',
    ]))->toThrow(LogicException::class, 'evidence or a reason');

    $response = QuoteCustomerResponseEvent::factory()
        ->employeeRecorded($membership->id, $membership->user_id, 'Customer confirmed by phone')
        ->create([
            'quote_revision_document_id' => $document->id,
        ]);

    expect($response->employee_recorded_reason)->toBe('Customer confirmed by phone');

    $rawReason = DB::table('quote_customer_response_events')
        ->where('id', $response->id)
        ->value('employee_recorded_reason');

    expect($rawReason)->not->toBe('Customer confirmed by phone')
        ->and($rawReason)->not->toBeNull();
});

test('phase 2d1 outbox idempotency is unique and status is not mass-assignable', function () {
    $row = IntegrationOutbox::factory()->create();

    expect($row->status->value)->toBe('pending');

    $row->fill(['status' => 'dispatched']);
    expect($row->isDirty('status'))->toBeFalse();

    $row->forceFill(['status' => 'dispatched'])->save();
    expect($row->fresh()->status->value)->toBe('dispatched');

    expect(fn () => IntegrationOutbox::factory()->create([
        'idempotency_key' => $row->idempotency_key,
    ]))->toThrow(QueryException::class);

    $contract = new QuoteAcceptanceAtomicityContract;
    $safe = [
        'quote_revision_id' => 9,
        'quote_id' => 3,
        'organization_id' => 1,
        'document_id' => 4,
    ];
    $contract->assertOutboxPayloadIsSafe($safe);

    expect(fn () => $contract->assertOutboxPayloadIsSafe([
        ...$safe,
        'raw_token' => 'secret',
    ]))->toThrow(InvalidArgumentException::class);

    expect($contract->designIdempotencyKey(9))
        ->toBe($contract->designIdempotencyKey(9))
        ->and($contract->designIdempotencyKey(9))->not->toBe($contract->designIdempotencyKey(10));
});

test('phase 2d1 customer payload snapshots omit forbidden keys', function () {
    $integrity = new CustomerQuoteDocumentIntegrity;
    $quote = QuoteFactory::createForDeal();
    $document = QuoteRevisionDocument::factory()->create([
        'quote_revision_id' => $quote->current_revision_id,
    ]);
    $encoded = json_encode($document->customer_payload_snapshot_json);

    foreach (CustomerSafeQuoteProjection::forbiddenKeys() as $key) {
        expect($encoded)->not->toContain('"'.$key.'"');
    }

    $payload = $document->customer_payload_snapshot_json;
    $integrity->assertNoForbiddenKeys($payload);
    expect($integrity->payloadChecksum($payload))->toBe($integrity->payloadChecksum($payload));
});

test('phase 2d1 rbac definitions add send and record_customer_response with the intended role split', function () {
    $keys = collect(RbacDefinitions::permissions())->pluck('key');
    $roles = RbacDefinitions::systemRoles();

    $newKeys = [
        'crm.quote.send',
        'crm.quote.record_customer_response',
    ];

    foreach ($newKeys as $key) {
        expect($keys)->toContain($key);
    }

    expect(array_intersect($newKeys, $roles['owner']['permissions']))->toBe($newKeys)
        ->and(array_intersect($newKeys, $roles['admin']['permissions']))->toBe($newKeys)
        ->and(array_values(array_intersect($newKeys, $roles['sales_manager']['permissions'])))->toBe($newKeys)
        ->and(array_values(array_intersect($newKeys, $roles['salesperson']['permissions'])))->toBe([
            'crm.quote.send',
        ])
        ->and(array_intersect($newKeys, $roles['finance']['permissions']))->toBe([])
        ->and(array_intersect($newKeys, $roles['project_manager']['permissions']))->toBe([])
        ->and(array_intersect($newKeys, $roles['production_worker']['permissions']))->toBe([]);

    foreach (RbacDefinitions::parentRoleKeys() as $parentRoleKey) {
        expect(array_intersect($newKeys, $roles[$parentRoleKey]['permissions']))->toBe([]);
    }
});

test('phase 2d1 cross-org pointers are rejected', function () {
    $quote = QuoteFactory::createForDeal();
    $document = QuoteRevisionDocument::factory()->create([
        'quote_revision_id' => $quote->current_revision_id,
    ]);
    $foreignMembership = Membership::factory()->create();
    $otherOrganization = Organization::factory()->create();

    expect(fn () => QuoteDelivery::factory()->create([
        'quote_revision_document_id' => $document->id,
        'requested_by_membership_id' => $foreignMembership->id,
        'requested_by_user_id' => $foreignMembership->user_id,
    ]))->toThrow(QueryException::class);

    expect(fn () => QuoteRevisionDocument::factory()->create([
        'quote_revision_id' => $quote->current_revision_id,
        'organization_id' => $otherOrganization->id,
        'parent_account_id' => $otherOrganization->parent_account_id,
        'document_version' => 99,
    ]))->toThrow(QueryException::class);
});

test('phase 2d1 rollback removes only the new schema and remigrate restores it', function () {
    expect(Schema::hasTable('quote_revision_documents'))->toBeTrue()
        ->and(DB::table('migrations')->whereIn('migration', PHASE_2D1_MIGRATIONS)->count())->toBe(6);

    phase2d1Rollback();

    foreach (PHASE_2D1_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    expect(Schema::hasColumn('quote_revisions', 'current_document_id'))->toBeFalse()
        ->and(Schema::hasTable('quotes'))->toBeTrue()
        ->and(Schema::hasTable('quote_revisions'))->toBeTrue()
        ->and(Schema::hasTable('quote_revision_tax_calculations'))->toBeTrue()
        ->and(Schema::hasColumn('quote_revisions', 'current_tax_calculation_id'))->toBeTrue()
        ->and(DB::table('migrations')->whereIn('migration', PHASE_2D1_MIGRATIONS)->count())->toBe(0);

    Artisan::call('migrate', ['--force' => true]);

    foreach (PHASE_2D1_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    expect(Schema::hasColumn('quote_revisions', 'current_document_id'))->toBeTrue();
});
