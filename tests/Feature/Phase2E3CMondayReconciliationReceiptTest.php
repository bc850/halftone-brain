<?php

use App\Enums\IntegrationConsumerOutcome;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationProviderReceiptDiscoveryMethod;
use App\Enums\IntegrationRemoteResourceType;
use App\Models\IntegrationProviderReceipt;
use App\Support\Integrations\Monday\Dto\MondayCreatedItemResult;
use App\Support\Integrations\Monday\MondayIntakeReconciliationService;
use App\Support\Integrations\Monday\MondayProviderReceiptService;

beforeEach(function (): void {
    $this->withoutVite();
});

test('phase 2e3c reconciliation returns success for existing local receipt without create calls', function () {
    $scenario = phase2e3cReconciliationFixture();

    IntegrationProviderReceipt::factory()->create([
        'integration_outbox_delivery_id' => $scenario['delivery']->id,
        'organization_id' => $scenario['ctx']['organization']->id,
        'parent_account_id' => $scenario['ctx']['parent']->id,
        'remote_id' => 'existing_item_1',
        'remote_board_id' => 'fake_board_100',
    ]);

    $result = app(MondayIntakeReconciliationService::class)->reconcile(
        delivery: $scenario['delivery'],
        settings: $scenario['settings'],
        integrationKey: $scenario['integrationKey'],
        correlationId: $scenario['outbox']->correlation_id,
    );

    expect($result->outcome)->toBe(IntegrationConsumerOutcome::Succeeded)
        ->and($result->providerReference['remote_id'] ?? null)->toBe('existing_item_1')
        ->and($scenario['fake']->recordedCreateRequests())->toHaveCount(0);
});

test('phase 2e3c reconciliation returns retryable when no remote item matches', function () {
    $scenario = phase2e3cReconciliationFixture();

    $result = app(MondayIntakeReconciliationService::class)->reconcile(
        delivery: $scenario['delivery'],
        settings: $scenario['settings'],
        integrationKey: $scenario['integrationKey'],
        correlationId: $scenario['outbox']->correlation_id,
    );

    expect($result->outcome)->toBe(IntegrationConsumerOutcome::RetryableFailure)
        ->and($result->errorCode)->toBe('not_found_for_reconciliation');
});

test('phase 2e3c reconciliation links reconciled receipt when one remote item matches', function () {
    $scenario = phase2e3cReconciliationFixture();

    $scenario['fake']->seedItem($scenario['integrationKey'], new MondayCreatedItemResult(
        itemId: 'remote_item_42',
        boardId: 'fake_board_100',
        itemUrl: 'https://monday.test/pulses/remote_item_42',
        idempotencyReplayed: false,
        providerRequestId: null,
        apiVersion: '2026-07',
    ));

    $result = app(MondayIntakeReconciliationService::class)->reconcile(
        delivery: $scenario['delivery'],
        settings: $scenario['settings'],
        integrationKey: $scenario['integrationKey'],
        correlationId: $scenario['outbox']->correlation_id,
    );

    $receipt = IntegrationProviderReceipt::query()->sole();

    expect($result->outcome)->toBe(IntegrationConsumerOutcome::Succeeded)
        ->and($result->providerReference['remote_id'] ?? null)->toBe('remote_item_42')
        ->and($receipt->remote_id)->toBe('remote_item_42')
        ->and($receipt->discovery_method)->toBe(IntegrationProviderReceiptDiscoveryMethod::Reconciled)
        ->and($receipt->provider)->toBe(IntegrationProvider::Monday)
        ->and($receipt->remote_resource_type)->toBe(IntegrationRemoteResourceType::Item);
});

test('phase 2e3c reconciliation returns permanent failure for ambiguous integration key', function () {
    $scenario = phase2e3cReconciliationFixture();
    $scenario['fake']->seedAmbiguousIntegrationKey($scenario['integrationKey']);

    $result = app(MondayIntakeReconciliationService::class)->reconcile(
        delivery: $scenario['delivery'],
        settings: $scenario['settings'],
        integrationKey: $scenario['integrationKey'],
        correlationId: $scenario['outbox']->correlation_id,
    );

    expect($result->outcome)->toBe(IntegrationConsumerOutcome::PermanentFailure)
        ->and($result->errorCode)->toBe('ambiguous_integration_key')
        ->and(IntegrationProviderReceipt::query()->count())->toBe(0);
});

test('phase 2e3c receipt service is idempotent when linking the same remote id twice', function () {
    $scenario = phase2e3cReconciliationFixture();
    $receipts = app(MondayProviderReceiptService::class);

    $result = new MondayCreatedItemResult(
        itemId: 'same_item_99',
        boardId: 'fake_board_100',
        itemUrl: 'https://monday.test/pulses/same_item_99',
        idempotencyReplayed: false,
        providerRequestId: 'req_1',
        apiVersion: '2026-07',
    );

    $first = $receipts->linkCreatedItem(
        delivery: $scenario['delivery'],
        result: $result,
        discoveryMethod: IntegrationProviderReceiptDiscoveryMethod::Created,
        correlationId: $scenario['outbox']->correlation_id,
    );

    $second = $receipts->linkCreatedItem(
        delivery: $scenario['delivery'],
        result: $result,
        discoveryMethod: IntegrationProviderReceiptDiscoveryMethod::Reconciled,
        correlationId: $scenario['outbox']->correlation_id,
    );

    expect($second->id)->toBe($first->id)
        ->and(IntegrationProviderReceipt::query()->count())->toBe(1);
});

test('phase 2e3c receipt service rejects conflicting remote id for same delivery', function () {
    $scenario = phase2e3cReconciliationFixture();
    $receipts = app(MondayProviderReceiptService::class);

    $receipts->linkCreatedItem(
        delivery: $scenario['delivery'],
        result: new MondayCreatedItemResult(
            itemId: 'item_alpha',
            boardId: 'fake_board_100',
            itemUrl: null,
            idempotencyReplayed: false,
            providerRequestId: null,
            apiVersion: '2026-07',
        ),
        discoveryMethod: IntegrationProviderReceiptDiscoveryMethod::Created,
        correlationId: $scenario['outbox']->correlation_id,
    );

    expect(fn () => $receipts->linkCreatedItem(
        delivery: $scenario['delivery'],
        result: new MondayCreatedItemResult(
            itemId: 'item_beta',
            boardId: 'fake_board_100',
            itemUrl: null,
            idempotencyReplayed: false,
            providerRequestId: null,
            apiVersion: '2026-07',
        ),
        discoveryMethod: IntegrationProviderReceiptDiscoveryMethod::Reconciled,
        correlationId: $scenario['outbox']->correlation_id,
    ))->toThrow(InvalidArgumentException::class, 'Conflicting Monday remote item identity');
});
