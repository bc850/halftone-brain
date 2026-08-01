<?php

use App\Enums\IntegrationOutboxDeliveryStatus;
use App\Enums\IntegrationOutboxStatus;
use App\Models\AuditEvent;
use App\Models\IntegrationOutbox;
use App\Models\IntegrationOutboxDelivery;
use App\Models\Organization;
use App\Support\Integrations\Outbox\Consumers\DiagnosticAcceptedQuoteProbeConsumer;
use App\Support\Integrations\Outbox\IntegrationClaimLock;
use App\Support\Integrations\Outbox\IntegrationConsumerHandler;
use App\Support\Integrations\Outbox\IntegrationConsumerRegistry;
use App\Support\Integrations\Outbox\IntegrationConsumerResult;
use App\Support\Integrations\Outbox\IntegrationDeliveryIdempotency;
use App\Support\Integrations\Outbox\IntegrationDeliveryLifecycleService;
use App\Support\Integrations\Outbox\IntegrationDeliveryProcessor;
use App\Support\Integrations\Outbox\IntegrationErrorSanitizer;
use App\Support\Integrations\Outbox\IntegrationLeaseReclaimer;
use App\Support\Integrations\Outbox\IntegrationOutboxBackoff;
use App\Support\Integrations\Outbox\IntegrationOutboxHealthReporter;
use App\Support\Integrations\Outbox\IntegrationOutboxMaterializer;
use App\Support\Quotes\Acceptance\QuoteAcceptanceAtomicityContract;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ScriptedIntegrationConsumer;

const PHASE_2E1_MIGRATION = '2026_08_01_032301_create_integration_outbox_deliveries_table';

function phase2e1HasIndex(string $table, string $indexName, bool $unique = false): bool
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

function phase2e1Registry(IntegrationConsumerHandler ...$extra): IntegrationConsumerRegistry
{
    $registry = new IntegrationConsumerRegistry;
    $registry->declareEventType(QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE);
    $registry->register(
        QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE,
        new DiagnosticAcceptedQuoteProbeConsumer,
    );

    foreach ($extra as $handler) {
        $registry->register(QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE, $handler);
    }

    return $registry;
}

/**
 * @param  list<IntegrationConsumerHandler>  $extra
 */
function phase2e1BindRegistry(IntegrationConsumerHandler ...$extra): IntegrationConsumerRegistry
{
    $registry = phase2e1Registry(...$extra);
    app()->instance(IntegrationConsumerRegistry::class, $registry);

    return $registry;
}

test('phase 2e1 deliveries migration creates expected schema and rolls back', function () {
    expect(Schema::hasTable('integration_outbox_deliveries'))->toBeTrue()
        ->and(phase2e1HasIndex('integration_outbox_deliveries', 'iod_outbox_consumer_uidx', true))->toBeTrue()
        ->and(phase2e1HasIndex('integration_outbox_deliveries', 'iod_idempotency_uidx', true))->toBeTrue()
        ->and(phase2e1HasIndex('integration_outbox_deliveries', 'iod_status_available_idx'))->toBeTrue();

    Artisan::call('migrate:rollback', ['--path' => 'database/migrations/'.PHASE_2E1_MIGRATION.'.php', '--force' => true]);

    expect(Schema::hasTable('integration_outbox_deliveries'))->toBeFalse()
        ->and(Schema::hasTable('integration_outbox'))->toBeTrue();

    Artisan::call('migrate', ['--path' => 'database/migrations/'.PHASE_2E1_MIGRATION.'.php', '--force' => true]);

    expect(Schema::hasTable('integration_outbox_deliveries'))->toBeTrue();
});

test('phase 2e1 delivery status is not mass assignable and idempotency is unique', function () {
    $outbox = IntegrationOutbox::factory()->create();
    $delivery = IntegrationOutboxDelivery::factory()->create([
        'integration_outbox_id' => $outbox->id,
    ]);

    expect($delivery->status)->toBe(IntegrationOutboxDeliveryStatus::Pending);

    $delivery->fill(['status' => IntegrationOutboxDeliveryStatus::Succeeded->value]);
    expect($delivery->isDirty('status'))->toBeFalse();

    $delivery->forceFill(['status' => IntegrationOutboxDeliveryStatus::Succeeded])->save();
    expect($delivery->fresh()->status)->toBe(IntegrationOutboxDeliveryStatus::Succeeded);

    expect(fn () => IntegrationOutboxDelivery::factory()->create([
        'integration_outbox_id' => $outbox->id,
        'consumer_key' => $delivery->consumer_key,
        'idempotency_key' => $delivery->idempotency_key,
    ]))->toThrow(QueryException::class);
});

test('phase 2e1 outbox factory payload matches accepted quote contract shape', function () {
    $outbox = IntegrationOutbox::factory()->create();
    $payload = $outbox->payload_json;

    expect($payload)->toHaveKeys([
        'quote_id',
        'quote_revision_id',
        'organization_id',
        'document_id',
        'document_version',
    ]);

    (new QuoteAcceptanceAtomicityContract)->assertOutboxPayloadIsSafe($payload);
});

test('phase 2e1 materializes diagnostic delivery for accepted quote events', function () {
    Http::preventStrayRequests();
    phase2e1BindRegistry();

    $outbox = IntegrationOutbox::factory()->create();

    $result = app(IntegrationOutboxMaterializer::class)->materializeBatch(limit: 10, workerId: 'worker-a');

    expect($result['claimed'])->toBe(1)
        ->and($result['dispatched'])->toBe(1)
        ->and($result['deliveries_created'])->toBe(1);

    $outbox->refresh();
    expect($outbox->status)->toBe(IntegrationOutboxStatus::Dispatched)
        ->and($outbox->dispatched_at)->not->toBeNull()
        ->and($outbox->locked_at)->toBeNull();

    $delivery = IntegrationOutboxDelivery::query()->where('integration_outbox_id', $outbox->id)->sole();
    expect($delivery->consumer_key)->toBe(DiagnosticAcceptedQuoteProbeConsumer::CONSUMER_KEY)
        ->and($delivery->idempotency_key)->toBe(IntegrationDeliveryIdempotency::design($outbox->id, $delivery->consumer_key))
        ->and($delivery->status)->toBe(IntegrationOutboxDeliveryStatus::Pending);
});

test('phase 2e1 fan-out creates independent deliveries per consumer', function () {
    Http::preventStrayRequests();
    $second = new ScriptedIntegrationConsumer(
        'integrations.diagnostic.second_probe',
        QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE,
        [IntegrationConsumerResult::succeeded(['provider' => 'scripted', 'remote_id' => 's1', 'resource_type' => 'test'])],
    );
    phase2e1BindRegistry($second);

    $outbox = IntegrationOutbox::factory()->create();
    app(IntegrationOutboxMaterializer::class)->materializeBatch(workerId: 'w1');

    $keys = IntegrationOutboxDelivery::query()
        ->where('integration_outbox_id', $outbox->id)
        ->orderBy('consumer_key')
        ->pluck('consumer_key')
        ->all();

    expect($keys)->toBe([
        DiagnosticAcceptedQuoteProbeConsumer::CONSUMER_KEY,
        'integrations.diagnostic.second_probe',
    ]);

    app()->instance(IntegrationConsumerRegistry::class, phase2e1Registry(
        new ScriptedIntegrationConsumer(
            'integrations.diagnostic.second_probe',
            QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE,
            [IntegrationConsumerResult::permanent('invalid_mapping', 'bad map')],
        ),
    ));

    app(IntegrationDeliveryProcessor::class)->processBatch(workerId: 'w2');

    $byKey = IntegrationOutboxDelivery::query()
        ->where('integration_outbox_id', $outbox->id)
        ->get()
        ->keyBy('consumer_key');

    expect($byKey[DiagnosticAcceptedQuoteProbeConsumer::CONSUMER_KEY]->status)
        ->toBe(IntegrationOutboxDeliveryStatus::Succeeded)
        ->and($byKey['integrations.diagnostic.second_probe']->status)
        ->toBe(IntegrationOutboxDeliveryStatus::Failed);
});

test('phase 2e1 duplicate materialization is idempotent', function () {
    phase2e1BindRegistry();
    $outbox = IntegrationOutbox::factory()->create();

    app(IntegrationOutboxMaterializer::class)->materializeBatch(workerId: 'w1');
    $firstCount = IntegrationOutboxDelivery::query()->count();

    $outbox->forceFill([
        'status' => IntegrationOutboxStatus::Pending,
        'dispatched_at' => null,
        'available_at' => now(),
    ])->save();

    app(IntegrationOutboxMaterializer::class)->materializeBatch(workerId: 'w2');

    expect(IntegrationOutboxDelivery::query()->count())->toBe($firstCount)
        ->and($outbox->fresh()->status)->toBe(IntegrationOutboxStatus::Dispatched);
});

test('phase 2e1 unknown event types die without blocking peers', function () {
    phase2e1BindRegistry();

    $known = IntegrationOutbox::factory()->create();
    $unknown = IntegrationOutbox::factory()->create([
        'event_type' => 'totally.unknown.event',
        'idempotency_key' => hash('sha256', 'unknown:'.$known->id),
    ]);

    app(IntegrationOutboxMaterializer::class)->materializeBatch(limit: 50, workerId: 'w1');

    expect($unknown->fresh()->status)->toBe(IntegrationOutboxStatus::Dead)
        ->and($unknown->fresh()->last_error_code)->toBe('unknown_event_type')
        ->and($known->fresh()->status)->toBe(IntegrationOutboxStatus::Dispatched)
        ->and(IntegrationOutboxDelivery::query()->where('integration_outbox_id', $known->id)->count())->toBe(1)
        ->and(IntegrationOutboxDelivery::query()->where('integration_outbox_id', $unknown->id)->count())->toBe(0);
});

test('phase 2e1 known event with zero consumers dispatches without deliveries', function () {
    $registry = new IntegrationConsumerRegistry;
    $registry->declareEventType(QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE);
    app()->instance(IntegrationConsumerRegistry::class, $registry);

    $outbox = IntegrationOutbox::factory()->create();
    app(IntegrationOutboxMaterializer::class)->materializeBatch(workerId: 'w1');

    expect($outbox->fresh()->status)->toBe(IntegrationOutboxStatus::Dispatched)
        ->and(IntegrationOutboxDelivery::query()->count())->toBe(0);
});

test('phase 2e1 retry scheduling and max attempts transition to dead', function () {
    $backoff = new IntegrationOutboxBackoff(
        baseSeconds: 5,
        maxSeconds: 3600,
        maxAttempts: 3,
        jitterPercentResolver: static fn (): int => 0,
    );

    $consumer = new ScriptedIntegrationConsumer(
        'integrations.diagnostic.retry_probe',
        QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE,
        [
            IntegrationConsumerResult::retryable('timeout', 'timed out'),
            IntegrationConsumerResult::retryable('timeout', 'timed out'),
            IntegrationConsumerResult::retryable('timeout', 'timed out'),
        ],
    );

    $registry = new IntegrationConsumerRegistry;
    $registry->declareEventType(QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE);
    $registry->register(QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE, $consumer);
    app()->instance(IntegrationConsumerRegistry::class, $registry);

    $outbox = IntegrationOutbox::factory()->create();
    app(IntegrationOutboxMaterializer::class)->materializeBatch(workerId: 'w1');

    $processor = new IntegrationDeliveryProcessor(
        $registry,
        app(IntegrationClaimLock::class),
        app(IntegrationErrorSanitizer::class),
        $backoff,
    );

    $processor->processBatch(workerId: 'w1');
    $delivery = IntegrationOutboxDelivery::query()->sole();
    expect($delivery->status)->toBe(IntegrationOutboxDeliveryStatus::Retrying)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->available_at->greaterThan(now()))->toBeTrue();

    $delivery->forceFill(['available_at' => now()->subSecond()])->save();
    $processor->processBatch(workerId: 'w2');
    expect($delivery->fresh()->attempt_count)->toBe(2)
        ->and($delivery->fresh()->status)->toBe(IntegrationOutboxDeliveryStatus::Retrying);

    $delivery->fresh()->forceFill(['available_at' => now()->subSecond()])->save();
    $processor->processBatch(workerId: 'w3');
    expect($delivery->fresh()->status)->toBe(IntegrationOutboxDeliveryStatus::Dead)
        ->and($delivery->fresh()->attempt_count)->toBe(3);
});

test('phase 2e1 blocked configuration does not consume attempts and can be released', function () {
    $consumer = new ScriptedIntegrationConsumer(
        'integrations.diagnostic.config_probe',
        QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE,
        [IntegrationConsumerResult::blockedConfiguration('missing_monday_board', 'Board mapping missing')],
    );

    $registry = new IntegrationConsumerRegistry;
    $registry->declareEventType(QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE);
    $registry->register(QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE, $consumer);
    app()->instance(IntegrationConsumerRegistry::class, $registry);

    $outbox = IntegrationOutbox::factory()->create();
    app(IntegrationOutboxMaterializer::class)->materializeBatch(workerId: 'w1');
    app(IntegrationDeliveryProcessor::class)->processBatch(workerId: 'w1');

    $delivery = IntegrationOutboxDelivery::query()->sole();
    expect($delivery->status)->toBe(IntegrationOutboxDeliveryStatus::BlockedConfiguration)
        ->and($delivery->attempt_count)->toBe(0)
        ->and($delivery->blocked_at)->not->toBeNull();

    $released = app(IntegrationDeliveryLifecycleService::class)->releaseBlockedConfiguration(
        Organization::query()->findOrFail($delivery->organization_id),
        $delivery->consumer_key,
    );

    expect($released)->toBe(1)
        ->and($delivery->fresh()->status)->toBe(IntegrationOutboxDeliveryStatus::Pending)
        ->and(AuditEvent::query()->where('action', 'integrations.outbox_delivery.configuration_released')->count())->toBe(1);
});

test('phase 2e1 replay and abandon enforce state rules and audit', function () {
    phase2e1BindRegistry();
    $outbox = IntegrationOutbox::factory()->create();
    app(IntegrationOutboxMaterializer::class)->materializeBatch(workerId: 'w1');
    $delivery = IntegrationOutboxDelivery::query()->sole();

    $delivery->forceFill([
        'status' => IntegrationOutboxDeliveryStatus::Dead,
        'attempt_count' => 4,
        'last_error_code' => 'timeout',
        'last_error_message' => 'boom',
    ])->save();

    $lifecycle = app(IntegrationDeliveryLifecycleService::class);
    $replayed = $lifecycle->replay($delivery, resetAttempts: false);

    expect($replayed->status)->toBe(IntegrationOutboxDeliveryStatus::Pending)
        ->and($replayed->attempt_count)->toBe(4)
        ->and(AuditEvent::query()->where('action', 'integrations.outbox_delivery.replayed')->count())->toBe(1);

    $succeeded = $delivery->fresh();
    $succeeded->forceFill(['status' => IntegrationOutboxDeliveryStatus::Succeeded, 'succeeded_at' => now()])->save();

    expect(fn () => $lifecycle->replay($succeeded))->toThrow(RuntimeException::class);

    $pending = IntegrationOutboxDelivery::factory()->create([
        'integration_outbox_id' => $outbox->id,
        'consumer_key' => 'integrations.diagnostic.abandon_probe',
        'idempotency_key' => IntegrationDeliveryIdempotency::design($outbox->id, 'integrations.diagnostic.abandon_probe'),
    ]);

    $abandoned = $lifecycle->abandon($pending, 'Owner abandoned poisoned row');
    expect($abandoned->status)->toBe(IntegrationOutboxDeliveryStatus::Abandoned)
        ->and(AuditEvent::query()->where('action', 'integrations.outbox_delivery.abandoned')->count())->toBe(1);
});

test('phase 2e1 lease reclaim recovers crashed processing rows', function () {
    phase2e1BindRegistry();
    $outbox = IntegrationOutbox::factory()->create([
        'status' => IntegrationOutboxStatus::Processing,
        'locked_at' => now()->subMinutes(10),
        'locked_by_worker' => 'crashed-worker',
    ]);

    $delivery = IntegrationOutboxDelivery::factory()->create([
        'integration_outbox_id' => $outbox->id,
        'status' => IntegrationOutboxDeliveryStatus::Processing,
        'locked_at' => now()->subMinutes(10),
        'locked_by_worker' => 'crashed-worker',
        'attempt_count' => 2,
    ]);

    config(['integrations.outbox.lease_seconds' => 60, 'integrations.deliveries.lease_seconds' => 60]);

    $result = app(IntegrationLeaseReclaimer::class)->reclaimExpired();

    expect($result['outbox_reclaimed'])->toBe(1)
        ->and($result['deliveries_reclaimed'])->toBe(1)
        ->and($outbox->fresh()->status)->toBe(IntegrationOutboxStatus::Pending)
        ->and($delivery->fresh()->status)->toBe(IntegrationOutboxDeliveryStatus::Retrying)
        ->and($delivery->fresh()->locked_at)->toBeNull();
});

test('phase 2e1 cross-organization isolation for processing filters', function () {
    phase2e1BindRegistry();
    $a = IntegrationOutbox::factory()->create();
    $b = IntegrationOutbox::factory()->create();

    expect($a->organization_id)->not->toBe($b->organization_id);

    app(IntegrationOutboxMaterializer::class)->materializeBatch(
        organizationId: (int) $a->organization_id,
        workerId: 'w1',
    );

    expect($a->fresh()->status)->toBe(IntegrationOutboxStatus::Dispatched)
        ->and($b->fresh()->status)->toBe(IntegrationOutboxStatus::Pending);
});

test('phase 2e1 health reporter returns expected keys', function () {
    phase2e1BindRegistry();
    IntegrationOutbox::factory()->create();
    app(IntegrationOutboxMaterializer::class)->materializeBatch(workerId: 'w1');
    app(IntegrationDeliveryProcessor::class)->processBatch(workerId: 'w1');

    $report = app(IntegrationOutboxHealthReporter::class)->report();

    expect($report)->toHaveKeys([
        'database',
        'outbox_by_status',
        'deliveries_by_status',
        'deliveries_by_consumer',
        'oldest_eligible_pending_age_seconds',
        'oldest_blocked_configuration_age_seconds',
        'last_successful_delivery_at',
        'dead_delivery_count',
        'currently_leased_count',
        'expired_lease_count',
    ])->and($report['outbox_by_status']['dispatched'])->toBeGreaterThanOrEqual(1)
        ->and($report['deliveries_by_status']['succeeded'])->toBeGreaterThanOrEqual(1)
        ->and($report['last_successful_delivery_at'])->not->toBeNull();
});

test('phase 2e1 sanitized errors never store bearer tokens', function () {
    $consumer = new ScriptedIntegrationConsumer(
        'integrations.diagnostic.secret_probe',
        QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE,
        [IntegrationConsumerResult::retryable('timeout', 'Authorization: Bearer abcdefghijklmnop failed')],
    );

    $registry = new IntegrationConsumerRegistry;
    $registry->declareEventType(QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE);
    $registry->register(QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE, $consumer);
    app()->instance(IntegrationConsumerRegistry::class, $registry);

    IntegrationOutbox::factory()->create();
    app(IntegrationOutboxMaterializer::class)->materializeBatch(workerId: 'w1');
    app(IntegrationDeliveryProcessor::class)->processBatch(workerId: 'w1');

    $delivery = IntegrationOutboxDelivery::query()->sole();
    expect($delivery->last_error_message)->not->toContain('abcdefghijklmnop')
        ->and($delivery->last_error_message)->toContain('[REDACTED]');
});

test('phase 2e1 commands require database confirmation for mutations', function () {
    $this->artisan('integrations:materialize-outbox')
        ->assertFailed();

    $this->artisan('integrations:materialize-outbox', ['--dry-run' => true])
        ->assertSuccessful();

    $this->artisan('integrations:outbox-health')
        ->assertSuccessful();
});

test('phase 2e1 processing never issues http requests', function () {
    Http::fake();
    Http::preventStrayRequests();
    phase2e1BindRegistry();

    IntegrationOutbox::factory()->count(3)->create();
    app(IntegrationOutboxMaterializer::class)->materializeBatch(workerId: 'w1');
    app(IntegrationDeliveryProcessor::class)->processBatch(workerId: 'w1');

    Http::assertNothingSent();
});
