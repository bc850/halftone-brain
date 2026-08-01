<?php

use App\Enums\IntegrationOutboxDeliveryStatus;
use App\Enums\IntegrationOutboxStatus;
use App\Models\AuditEvent;
use App\Models\IntegrationOutbox;
use App\Models\IntegrationOutboxDelivery;
use App\Models\Organization;
use App\Support\Integrations\Outbox\Consumers\DiagnosticAcceptedQuoteProbeConsumer;
use App\Support\Integrations\Outbox\IntegrationDeliveryIdempotency;
use App\Support\Integrations\Outbox\IntegrationDeliveryLifecycleService;
use App\Support\Integrations\Outbox\IntegrationOperationalProjection;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
});

function phase2e2SeedDelivery(
    Organization $organization,
    IntegrationOutboxDeliveryStatus $status = IntegrationOutboxDeliveryStatus::Failed,
    ?string $consumerKey = null,
): array {
    $consumerKey ??= DiagnosticAcceptedQuoteProbeConsumer::CONSUMER_KEY;

    $outbox = IntegrationOutbox::factory()->create([
        'organization_id' => $organization->id,
        'parent_account_id' => $organization->parent_account_id,
        'status' => IntegrationOutboxStatus::Dispatched,
        'dispatched_at' => now(),
        'payload_json' => [
            'quote_id' => 101,
            'quote_revision_id' => 202,
            'organization_id' => $organization->id,
            'document_id' => 303,
            'document_version' => 1,
            'raw_token' => 'should-never-appear',
            'material_cost_micro_units' => 999,
        ],
    ]);

    $delivery = IntegrationOutboxDelivery::factory()->create([
        'integration_outbox_id' => $outbox->id,
        'organization_id' => $organization->id,
        'parent_account_id' => $organization->parent_account_id,
        'consumer_key' => $consumerKey,
        'idempotency_key' => IntegrationDeliveryIdempotency::design($outbox->id, $consumerKey),
        'status' => $status,
        'attempt_count' => 2,
        'last_error_code' => 'timeout',
        'last_error_message' => 'Authorization: Bearer secret-token-value timed out',
        'provider_reference_json' => [
            'provider' => 'diagnostic',
            'remote_id' => 'probe-1',
            'authorization' => 'Bearer leak',
            'response_body' => '{"token":"x"}',
        ],
        'correlation_id' => $outbox->correlation_id,
    ]);

    return compact('outbox', 'delivery');
}

test('phase 2e2 owner can view outbox index and delivery detail', function () {
    $ctx = createTenantUser('owner');
    $seed = phase2e2SeedDelivery($ctx['organization']);

    $this->actingAs($ctx['user'])
        ->get(route('org.integrations.outbox.index', $ctx['organization']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('integrations/OutboxIndex')
            ->has('deliveries.data', 1)
            ->where('canReplay', true)
            ->where('canAbandon', true)
            ->where('deliveries.data.0.problem_summary', fn ($value) => ! str_contains((string) $value, 'secret-token-value')));

    $this->actingAs($ctx['user'])
        ->get(route('org.integrations.outbox.deliveries.show', [
            $ctx['organization'],
            $seed['delivery'],
        ]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('integrations/OutboxDeliveryShow')
            ->where('delivery.can_replay', true)
            ->missing('delivery.provider_reference.authorization')
            ->missing('delivery.provider_reference.response_body')
            ->where('payload_fields', fn ($fields) => collect($fields)->pluck('key')->doesntContain('raw_token')));
});

test('phase 2e2 cross-organization delivery detail is 404', function () {
    $ownerA = createTenantUser('owner');
    $ownerB = createTenantUser('owner');
    $seed = phase2e2SeedDelivery($ownerA['organization']);

    $this->actingAs($ownerB['user'])
        ->get(route('org.integrations.outbox.deliveries.show', [
            $ownerB['organization'],
            $seed['delivery'],
        ]))
        ->assertNotFound();
});

test('phase 2e2 sales manager is view-only', function () {
    $ctx = createTenantUser('sales_manager');
    $seed = phase2e2SeedDelivery($ctx['organization']);

    $this->actingAs($ctx['user'])
        ->get(route('org.integrations.outbox.index', $ctx['organization']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('canReplay', false)
            ->where('canAbandon', false)
            ->where('deliveries.data.0.can_replay', false)
            ->where('deliveries.data.0.can_abandon', false));

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.outbox.deliveries.replay', [
            $ctx['organization'],
            $seed['delivery'],
        ]), [
            'reason' => 'Should be forbidden',
            'expected_status' => 'failed',
        ])
        ->assertForbidden();

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.outbox.deliveries.abandon', [
            $ctx['organization'],
            $seed['delivery'],
        ]), [
            'reason' => 'Should be forbidden',
            'expected_status' => 'failed',
            'confirm' => '1',
        ])
        ->assertForbidden();
});

test('phase 2e2 salesperson cannot view integration outbox', function () {
    $ctx = createTenantUser('salesperson');

    $this->actingAs($ctx['user'])
        ->get(route('org.integrations.outbox.index', $ctx['organization']))
        ->assertForbidden();
});

test('phase 2e2 owner replay requires reason and does not process synchronously', function () {
    Http::fake();
    Http::preventStrayRequests();

    $ctx = createTenantUser('owner');
    $seed = phase2e2SeedDelivery($ctx['organization'], IntegrationOutboxDeliveryStatus::Dead);
    $originalPayload = $seed['outbox']->payload_json;

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.outbox.deliveries.replay', [
            $ctx['organization'],
            $seed['delivery'],
        ]), [
            'reason' => 'ab',
            'expected_status' => 'dead',
        ])
        ->assertSessionHasErrors('reason');

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.outbox.deliveries.replay', [
            $ctx['organization'],
            $seed['delivery'],
        ]), [
            'reason' => 'Retry after worker fix',
            'expected_status' => 'dead',
        ])
        ->assertRedirect();

    $seed['delivery']->refresh();
    $seed['outbox']->refresh();

    expect($seed['delivery']->status)->toBe(IntegrationOutboxDeliveryStatus::Pending)
        ->and($seed['delivery']->locked_at)->toBeNull()
        ->and($seed['outbox']->payload_json)->toBe($originalPayload)
        ->and(AuditEvent::query()->where('action', IntegrationDeliveryLifecycleService::AUDIT_REPLAYED)->count())->toBe(1);

    Http::assertNothingSent();
});

test('phase 2e2 stale expected status returns 409', function () {
    $ctx = createTenantUser('owner');
    $seed = phase2e2SeedDelivery($ctx['organization'], IntegrationOutboxDeliveryStatus::Failed);

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.outbox.deliveries.replay', [
            $ctx['organization'],
            $seed['delivery'],
        ]), [
            'reason' => 'Stale client view',
            'expected_status' => 'dead',
        ])
        ->assertStatus(409);
});

test('phase 2e2 abandon succeeded is rejected and pending can be abandoned', function () {
    $ctx = createTenantUser('owner');
    $succeeded = phase2e2SeedDelivery($ctx['organization'], IntegrationOutboxDeliveryStatus::Succeeded);
    $pending = phase2e2SeedDelivery($ctx['organization'], IntegrationOutboxDeliveryStatus::Pending);

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.outbox.deliveries.abandon', [
            $ctx['organization'],
            $succeeded['delivery'],
        ]), [
            'reason' => 'Cannot abandon success',
            'expected_status' => 'succeeded',
            'confirm' => '1',
        ])
        ->assertStatus(409);

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.outbox.deliveries.abandon', [
            $ctx['organization'],
            $pending['delivery'],
        ]), [
            'reason' => 'Poisoned row retired',
            'expected_status' => 'pending',
            'confirm' => '1',
        ])
        ->assertRedirect();

    expect($pending['delivery']->fresh()->status)->toBe(IntegrationOutboxDeliveryStatus::Abandoned)
        ->and($pending['delivery']->fresh()->abandoned_at)->not->toBeNull()
        ->and(IntegrationOutbox::query()->whereKey($pending['outbox']->id)->exists())->toBeTrue();
});

test('phase 2e2 actively leased delivery cannot be abandoned', function () {
    $ctx = createTenantUser('owner');
    $seed = phase2e2SeedDelivery($ctx['organization'], IntegrationOutboxDeliveryStatus::Processing);
    $seed['delivery']->forceFill([
        'locked_at' => now(),
        'locked_by_worker' => 'worker-1',
    ])->save();

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.outbox.deliveries.abandon', [
            $ctx['organization'],
            $seed['delivery'],
        ]), [
            'reason' => 'Still leased',
            'expected_status' => 'processing',
            'confirm' => '1',
        ])
        ->assertStatus(409);
});

test('phase 2e2 operational projection omits unknown and forbidden payload keys', function () {
    $ctx = createTenantUser('owner');
    $seed = phase2e2SeedDelivery($ctx['organization']);

    $fields = app(IntegrationOperationalProjection::class)->projectPayload($seed['outbox']);
    $keys = collect($fields)->pluck('key')->all();

    expect($keys)->toBe([
        'quote_id',
        'quote_revision_id',
        'organization_id',
        'document_id',
        'document_version',
    ]);

    $provider = app(IntegrationOperationalProjection::class)->projectProviderReference([
        'provider' => 'diagnostic',
        'remote_id' => '1',
        'authorization' => 'Bearer x',
        'response_body' => '{}',
    ]);

    expect($provider)->toBe([
        'provider' => 'diagnostic',
        'remote_id' => '1',
    ]);
});

test('phase 2e2 health endpoint redirects for authorized viewers', function () {
    $ctx = createTenantUser('sales_manager');

    $this->actingAs($ctx['user'])
        ->get(route('org.integrations.outbox.health', $ctx['organization']))
        ->assertRedirect(route('org.integrations.outbox.index', $ctx['organization']));
});

test('phase 2e2 tenant-scoped index excludes foreign organization deliveries', function () {
    $ownerA = createTenantUser('owner');
    $ownerB = createTenantUser('owner');
    phase2e2SeedDelivery($ownerA['organization']);
    phase2e2SeedDelivery($ownerB['organization']);

    $this->actingAs($ownerA['user'])
        ->get(route('org.integrations.outbox.index', $ownerA['organization']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->has('deliveries.data', 1));
});

test('phase 2e2 denied org roles cannot view outbox', function (string $role) {
    $ctx = createTenantUser($role);

    $this->actingAs($ctx['user'])
        ->get(route('org.integrations.outbox.index', $ctx['organization']))
        ->assertForbidden();
})->with([
    'project_manager',
    'production_worker',
    'finance',
]);

test('phase 2e2 non-member cannot access another organization outbox', function () {
    $owner = createTenantUser('owner');
    $outsider = createTenantUser('owner');

    $this->actingAs($outsider['user'])
        ->get(route('org.integrations.outbox.index', $owner['organization']))
        ->assertNotFound();
});

test('phase 2e2 empty index still renders health cards', function () {
    $ctx = createTenantUser('owner');

    $this->actingAs($ctx['user'])
        ->get(route('org.integrations.outbox.index', $ctx['organization']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('integrations/OutboxIndex')
            ->has('deliveries.data', 0)
            ->where('health.waiting', 0)
            ->where('health.failed', 0)
            ->where('health.active_lease_count', 0));
});

test('phase 2e2 status filter and include_completed toggle', function () {
    $ctx = createTenantUser('owner');
    phase2e2SeedDelivery($ctx['organization'], IntegrationOutboxDeliveryStatus::Failed);
    phase2e2SeedDelivery($ctx['organization'], IntegrationOutboxDeliveryStatus::Succeeded);

    $this->actingAs($ctx['user'])
        ->get(route('org.integrations.outbox.index', [
            $ctx['organization'],
            'status' => 'failed',
        ]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->has('deliveries.data', 1)
            ->where('deliveries.data.0.status', 'failed'));

    $this->actingAs($ctx['user'])
        ->get(route('org.integrations.outbox.index', $ctx['organization']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->has('deliveries.data', 1));

    $this->actingAs($ctx['user'])
        ->get(route('org.integrations.outbox.index', [
            $ctx['organization'],
            'include_completed' => 1,
        ]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->has('deliveries.data', 2));
});

test('phase 2e2 health cards are tenant scoped', function () {
    $ownerA = createTenantUser('owner');
    $ownerB = createTenantUser('owner');
    phase2e2SeedDelivery($ownerA['organization'], IntegrationOutboxDeliveryStatus::Failed);
    phase2e2SeedDelivery($ownerB['organization'], IntegrationOutboxDeliveryStatus::Dead);

    $this->actingAs($ownerA['user'])
        ->get(route('org.integrations.outbox.index', $ownerA['organization']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('health.failed', 1)
            ->where('health.dead', 0));
});

test('phase 2e2 lease flags distinguish active versus expired', function () {
    $ctx = createTenantUser('owner');
    $active = phase2e2SeedDelivery($ctx['organization'], IntegrationOutboxDeliveryStatus::Processing);
    $active['delivery']->forceFill([
        'locked_at' => now(),
        'locked_by_worker' => 'worker-a',
    ])->save();

    $expired = phase2e2SeedDelivery($ctx['organization'], IntegrationOutboxDeliveryStatus::Processing);
    $expired['delivery']->forceFill([
        'locked_at' => now()->subMinutes(30),
        'locked_by_worker' => 'worker-b',
    ])->save();

    $projection = app(IntegrationOperationalProjection::class);

    $activeView = $projection->projectDelivery($active['delivery']->fresh(), true, true);
    $expiredView = $projection->projectDelivery($expired['delivery']->fresh(), true, true);

    expect($activeView['lease_active'])->toBeTrue()
        ->and($activeView['lease_expired'])->toBeFalse()
        ->and($activeView['can_abandon'])->toBeFalse()
        ->and($expiredView['lease_active'])->toBeFalse()
        ->and($expiredView['lease_expired'])->toBeTrue()
        ->and($expiredView['can_abandon'])->toBeTrue();
});

test('phase 2e2 replay audit snapshot excludes payload and provider bodies', function () {
    $ctx = createTenantUser('owner');
    $seed = phase2e2SeedDelivery($ctx['organization'], IntegrationOutboxDeliveryStatus::Failed);

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.outbox.deliveries.replay', [
            $ctx['organization'],
            $seed['delivery'],
        ]), [
            'reason' => 'Audit redaction check',
            'expected_status' => 'failed',
        ])
        ->assertRedirect();

    $audit = AuditEvent::query()
        ->where('action', IntegrationDeliveryLifecycleService::AUDIT_REPLAYED)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull();

    $encoded = json_encode([$audit->before_json, $audit->after_json]);

    expect($encoded)->not->toContain('should-never-appear')
        ->and($encoded)->not->toContain('secret-token-value')
        ->and($encoded)->not->toContain('response_body')
        ->and($encoded)->not->toContain('Bearer leak');
});

test('phase 2e2 replayable status matrix', function (IntegrationOutboxDeliveryStatus $status, bool $allowed) {
    $ctx = createTenantUser('owner');
    $seed = phase2e2SeedDelivery($ctx['organization'], $status);

    $response = $this->actingAs($ctx['user'])
        ->post(route('org.integrations.outbox.deliveries.replay', [
            $ctx['organization'],
            $seed['delivery'],
        ]), [
            'reason' => 'Matrix coverage',
            'expected_status' => $status->value,
        ]);

    if ($allowed) {
        $response->assertRedirect();
        expect($seed['delivery']->fresh()->status)->toBe(IntegrationOutboxDeliveryStatus::Pending);
    } else {
        $response->assertStatus(409);
    }
})->with([
    'failed' => [IntegrationOutboxDeliveryStatus::Failed, true],
    'dead' => [IntegrationOutboxDeliveryStatus::Dead, true],
    'blocked_configuration' => [IntegrationOutboxDeliveryStatus::BlockedConfiguration, true],
    'pending' => [IntegrationOutboxDeliveryStatus::Pending, false],
    'retrying' => [IntegrationOutboxDeliveryStatus::Retrying, false],
    'processing' => [IntegrationOutboxDeliveryStatus::Processing, false],
    'succeeded' => [IntegrationOutboxDeliveryStatus::Succeeded, false],
    'abandoned' => [IntegrationOutboxDeliveryStatus::Abandoned, false],
]);
