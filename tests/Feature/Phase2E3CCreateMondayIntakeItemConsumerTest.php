<?php

use App\Enums\IntegrationConsumerOutcome;
use App\Enums\IntegrationProviderReceiptDiscoveryMethod;
use App\Enums\IntegrationValidationStatus;
use App\Models\AuditEvent;
use App\Models\IntegrationOutbox;
use App\Models\IntegrationOutboxDelivery;
use App\Models\IntegrationProviderReceipt;
use App\Support\Integrations\Monday\Dto\MondayCreatedItemResult;
use App\Support\Integrations\Monday\MondayConsumerKeys;
use App\Support\Integrations\Monday\MondayProviderReceiptService;
use App\Support\Integrations\Outbox\Consumers\CreateMondayIntakeItemConsumer;
use App\Support\Integrations\Outbox\IntegrationConsumerRegistry;
use App\Support\Integrations\Outbox\IntegrationConsumerResult;
use App\Support\Quotes\Acceptance\QuoteAcceptanceAtomicityContract;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->withoutVite();

    if (DB::transactionLevel() > 0) {
        DB::commit();
    }
});

test('phase 2e3c consumer blocks when monday settings are missing disabled or stale', function (array $overrides, bool $createSettings, string $expectedCode) {
    phase2e3cClearCredentials();
    $scenario = phase2e3cConsumerFixture($overrides, $createSettings);

    $result = app(CreateMondayIntakeItemConsumer::class)->handle($scenario['outbox'], $scenario['delivery']);

    expect($result->outcome)->toBe(IntegrationConsumerOutcome::BlockedConfiguration)
        ->and($result->errorCode)->toBe($expectedCode)
        ->and($scenario['fake']->recordedCreateRequests())->toHaveCount(0);
})->with([
    'settings missing' => [[], false, 'settings_missing'],
    'settings disabled' => [['enabled' => false], true, 'settings_disabled'],
    'settings stale' => [['last_validation_status' => IntegrationValidationStatus::NeverValidated, 'last_validated_at' => null], true, 'settings_invalid_or_stale'],
]);

test('phase 2e3c consumer blocks when monday credentials are not configured', function () {
    $scenario = phase2e3cConsumerFixture();
    phase2e3cClearCredentials();

    $result = app(CreateMondayIntakeItemConsumer::class)->handle($scenario['outbox'], $scenario['delivery']);

    expect($result->outcome)->toBe(IntegrationConsumerOutcome::BlockedConfiguration)
        ->and($result->errorCode)->toBe('client_not_configured')
        ->and($scenario['fake']->recordedCreateRequests())->toHaveCount(0);
});

test('phase 2e3c consumer succeeds idempotently when receipt already exists', function () {
    $scenario = phase2e3cConsumerFixture();

    IntegrationProviderReceipt::factory()->create([
        'integration_outbox_delivery_id' => $scenario['delivery']->id,
        'organization_id' => $scenario['ctx']['organization']->id,
        'parent_account_id' => $scenario['ctx']['parent']->id,
        'remote_id' => 'already_linked',
        'remote_board_id' => 'fake_board_100',
    ]);

    $result = app(CreateMondayIntakeItemConsumer::class)->handle($scenario['outbox'], $scenario['delivery']);

    expect($result->outcome)->toBe(IntegrationConsumerOutcome::Succeeded)
        ->and($result->providerReference['remote_id'] ?? null)->toBe('already_linked')
        ->and($scenario['fake']->recordedCreateRequests())->toHaveCount(0)
        ->and(IntegrationProviderReceipt::query()->count())->toBe(1);
});

test('phase 2e3c consumer success path creates one receipt audit and single create call', function () {
    $logMessages = phase2e3cStartLogCapture();
    $scenario = phase2e3cConsumerFixture();

    $revisionBefore = $scenario['revision']->fresh();
    $quoteBefore = $scenario['quote']->fresh();
    $dealBefore = $scenario['deal']->fresh();

    $result = app(CreateMondayIntakeItemConsumer::class)->handle($scenario['outbox'], $scenario['delivery']);

    expect($result->outcome)->toBe(IntegrationConsumerOutcome::Succeeded)
        ->and($scenario['fake']->recordedCreateRequests())->toHaveCount(1)
        ->and(IntegrationProviderReceipt::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', MondayProviderReceiptService::AUDIT_ITEM_LINKED)->count())->toBe(1);

    $revisionAfter = $revisionBefore->fresh();
    $quoteAfter = $quoteBefore->fresh();
    $dealAfter = $dealBefore->fresh();

    expect($revisionAfter->status)->toBe($revisionBefore->status)
        ->and($revisionAfter->accepted_at?->toIso8601String())->toBe($revisionBefore->accepted_at?->toIso8601String())
        ->and($quoteAfter->updated_at?->toIso8601String())->toBe($quoteBefore->updated_at?->toIso8601String())
        ->and($dealAfter->stage)->toBe($dealBefore->stage);

    $delivery = $scenario['delivery']->fresh();
    $allowedKeys = [
        'provider',
        'resource_type',
        'remote_id',
        'remote_board_id',
        'remote_url',
        'provider_request_id',
        'idempotency_replayed',
        'api_version',
        'idempotency_key',
    ];

    expect(array_keys($delivery->provider_reference_json ?? []))->toEqual($allowedKeys)
        ->and($delivery->provider_reference_json)->not->toHaveKey('discovery_method');

    phase2e3cAssertLogsExcludeToken($logMessages, 'test-monday-personal-token');
});

test('phase 2e3c consumer maps retryable rate limited blocked and permanent client failures', function (string $failure, IntegrationConsumerOutcome $expectedOutcome, string $expectedCode) {
    $scenario = phase2e3cConsumerFixture();
    $scenario['fake']->failNext($failure);

    $result = app(CreateMondayIntakeItemConsumer::class)->handle($scenario['outbox'], $scenario['delivery']);

    expect($result->outcome)->toBe($expectedOutcome)
        ->and($result->errorCode)->toBe($expectedCode)
        ->and(IntegrationProviderReceipt::query()->count())->toBe(0);
})->with([
    'retryable timeout' => ['timeout', IntegrationConsumerOutcome::RetryableFailure, 'timeout'],
    'rate limited' => ['rate_limit', IntegrationConsumerOutcome::RetryableFailure, 'rate_limited'],
    'blocked unauthorized' => ['unauthorized', IntegrationConsumerOutcome::BlockedConfiguration, 'unauthorized'],
    'blocked configuration' => ['configuration', IntegrationConsumerOutcome::BlockedConfiguration, 'configuration_error'],
    'permanent graphql' => ['graphql', IntegrationConsumerOutcome::PermanentFailure, 'graphql_error'],
]);

test('phase 2e3c consumer reconciles after uncertain timeout when remote item exists', function () {
    $scenario = phase2e3cConsumerFixture();

    $scenario['fake']->seedItem($scenario['integrationKey'], new MondayCreatedItemResult(
        itemId: 'reconciled_remote_item',
        boardId: 'fake_board_100',
        itemUrl: 'https://monday.test/pulses/reconciled_remote_item',
        idempotencyReplayed: false,
        providerRequestId: null,
        apiVersion: '2026-07',
    ));
    $scenario['fake']->failNext('uncertain_timeout');

    $result = app(CreateMondayIntakeItemConsumer::class)->handle($scenario['outbox'], $scenario['delivery']);

    $receipt = IntegrationProviderReceipt::query()->sole();

    expect($result->outcome)->toBe(IntegrationConsumerOutcome::Succeeded)
        ->and($receipt->remote_id)->toBe('reconciled_remote_item')
        ->and($receipt->discovery_method)->toBe(IntegrationProviderReceiptDiscoveryMethod::Reconciled);
});

test('phase 2e3c consumer rejects cross organization tenant mismatch as permanent', function () {
    $scenario = phase2e3cConsumerFixture();
    $other = createTenantUser('owner');

    phase2e3cEstablishTenant($other);

    $result = app(CreateMondayIntakeItemConsumer::class)->handle($scenario['outbox'], $scenario['delivery']);

    expect($result->outcome)->toBe(IntegrationConsumerOutcome::PermanentFailure)
        ->and($result->errorCode)->toBe('tenant_mismatch');
});

test('phase 2e3c consumer rejects open database transaction boundary', function () {
    $scenario = phase2e3cConsumerFixture();

    $result = DB::transaction(fn (): IntegrationConsumerResult => app(CreateMondayIntakeItemConsumer::class)->handle(
        $scenario['outbox'],
        $scenario['delivery'],
    ));

    expect($result->outcome)->toBe(IntegrationConsumerOutcome::PermanentFailure)
        ->and($result->errorCode)->toBe('transaction_boundary_violation')
        ->and($scenario['fake']->recordedCreateRequests())->toHaveCount(0);
});

test('phase 2e3c consumer second handle does not duplicate outbox delivery or receipt', function () {
    $scenario = phase2e3cConsumerFixture();
    $consumer = app(CreateMondayIntakeItemConsumer::class);

    $first = $consumer->handle($scenario['outbox'], $scenario['delivery']);
    $second = $consumer->handle($scenario['outbox']->fresh(), $scenario['delivery']->fresh());

    expect($first->outcome)->toBe(IntegrationConsumerOutcome::Succeeded)
        ->and($second->outcome)->toBe(IntegrationConsumerOutcome::Succeeded)
        ->and(IntegrationOutbox::query()->count())->toBe(1)
        ->and(IntegrationOutboxDelivery::query()->count())->toBe(1)
        ->and(IntegrationProviderReceipt::query()->count())->toBe(1)
        ->and($scenario['fake']->recordedCreateRequests())->toHaveCount(1);
});

test('phase 2e3c monday intake consumer remains unregistered in integration registry', function () {
    $registry = app(IntegrationConsumerRegistry::class);

    expect($registry->handler(
        QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE,
        MondayConsumerKeys::CREATE_INTAKE_ITEM,
    ))->toBeNull();
});

test('phase 2e3c consumer security redacts token from logs audits and provider references', function () {
    $token = 'super-secret-monday-personal-token-value';
    $logMessages = phase2e3cStartLogCapture();
    phase2e3cBindTestCredentials($token);

    $scenario = phase2e3cConsumerFixture();
    phase2e3cBindFakeClient($scenario['fake']);

    $result = app(CreateMondayIntakeItemConsumer::class)->handle($scenario['outbox'], $scenario['delivery']);

    expect($result->outcome)->toBe(IntegrationConsumerOutcome::Succeeded);

    $audit = AuditEvent::query()->where('action', MondayProviderReceiptService::AUDIT_ITEM_LINKED)->sole();
    $auditJson = json_encode($audit->after_json) ?: '';

    expect($auditJson)->not->toContain($token)
        ->and($auditJson)->not->toContain('Bearer')
        ->and(json_encode($result->providerReference))->not->toContain($token)
        ->and(json_encode($scenario['delivery']->fresh()->provider_reference_json))->not->toContain($token);

    phase2e3cAssertLogsExcludeToken($logMessages, $token);
});
