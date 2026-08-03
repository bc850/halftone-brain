<?php

use App\Enums\MondayOutcomeClassification;
use App\Support\Integrations\Monday\Dto\MondayCreateItemRequest;
use App\Support\Integrations\Monday\MondayApiClientException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
});

function phase2e3cSampleCreateRequest(?string $idempotencyKey = 'delivery-key-abc'): MondayCreateItemRequest
{
    return new MondayCreateItemRequest(
        boardId: 'fake_board_100',
        groupId: 'fake_group_100',
        itemName: 'Q-100 — Acme Corp',
        integrationKey: 'org:1:quote:2:rev:1',
        columnValues: [
            'text_integration_key' => 'org:1:quote:2:rev:1',
            'numbers_grand_total' => '108.00',
        ],
        apiVersion: '2026-07',
        idempotencyKey: $idempotencyKey,
    );
}

test('phase 2e3c http client posts create with raw token api version and json string column values', function () {
    Http::fake([
        'api.monday.com/v2' => Http::response([
            'data' => [
                'create_item' => [
                    'id' => 'item_999',
                    'name' => 'Q-100 — Acme Corp',
                    'url' => 'https://monday.test/pulses/item_999',
                    'board' => ['id' => 'fake_board_100'],
                ],
            ],
        ], 200, [
            'Idempotency-Replayed' => 'true',
            'X-Request-Id' => 'req-create-1',
        ]),
    ]);

    $client = phase2e3cBuildHttpClient();
    $request = phase2e3cSampleCreateRequest();
    $result = $client->createIntakeItem($request);

    expect($result->itemId)->toBe('item_999')
        ->and($result->boardId)->toBe('fake_board_100')
        ->and($result->itemUrl)->toBe('https://monday.test/pulses/item_999')
        ->and($result->idempotencyReplayed)->toBeTrue()
        ->and($result->providerRequestId)->toBe('req-create-1');

    Http::assertSent(function ($httpRequest) use ($request): bool {
        if ($httpRequest->url() !== 'https://api.monday.com/v2') {
            return false;
        }

        $auth = $httpRequest->header('Authorization')[0] ?? '';
        $apiVersion = $httpRequest->header('API-Version')[0] ?? '';
        $idempotency = $httpRequest->header('Idempotency-Key')[0] ?? '';
        $variables = $httpRequest->data()['variables'] ?? [];

        return $auth === 'test-monday-personal-token'
            && ! str_starts_with($auth, 'Bearer ')
            && $apiVersion === '2026-07'
            && $idempotency === $request->resolvedIdempotencyKey()
            && ($variables['boardId'] ?? null) === 'fake_board_100'
            && ($variables['groupId'] ?? null) === 'fake_group_100'
            && ($variables['itemName'] ?? null) === 'Q-100 — Acme Corp'
            && is_string($variables['columnValues'] ?? null)
            && json_decode((string) $variables['columnValues'], true) === $request->columnValues;
    });

    expect(Http::recorded())->toHaveCount(1);
});

test('phase 2e3c http client uses deterministic idempotency key from board and integration key when delivery key absent', function () {
    Http::fake([
        'api.monday.com/v2' => Http::response([
            'data' => [
                'create_item' => [
                    'id' => 'item_1001',
                    'name' => 'Item',
                    'url' => null,
                    'board' => ['id' => 'fake_board_100'],
                ],
            ],
        ], 200),
    ]);

    $request = new MondayCreateItemRequest(
        boardId: 'board_a',
        groupId: 'group_a',
        itemName: 'Name',
        integrationKey: 'org:9:quote:8:rev:1',
        columnValues: ['text_integration_key' => 'org:9:quote:8:rev:1'],
        idempotencyKey: null,
    );

    phase2e3cBuildHttpClient()->createIntakeItem($request);

    Http::assertSent(fn ($httpRequest): bool => ($httpRequest->header('Idempotency-Key')[0] ?? null) === hash('sha256', 'board_a|org:9:quote:8:rev:1'));
});

test('phase 2e3c http client does not retry on server error', function () {
    Http::fake([
        'api.monday.com/v2' => Http::response(['error' => 'boom'], 500),
    ]);

    try {
        phase2e3cBuildHttpClient()->createIntakeItem(phase2e3cSampleCreateRequest());
        expect(false)->toBeTrue('Expected MondayApiClientException');
    } catch (MondayApiClientException $exception) {
        expect($exception->error->classification)->toBe(MondayOutcomeClassification::Retryable)
            ->and($exception->error->code)->toBe('server_error');
    }

    expect(Http::recorded())->toHaveCount(1);
});

test('phase 2e3c http client maps 409 to retryable idempotency conflict', function () {
    Http::fake([
        'api.monday.com/v2' => Http::response(['error' => 'conflict'], 409, ['Retry-After' => '7']),
    ]);

    try {
        phase2e3cBuildHttpClient()->createIntakeItem(phase2e3cSampleCreateRequest());
        expect(false)->toBeTrue('Expected MondayApiClientException');
    } catch (MondayApiClientException $exception) {
        expect($exception->error->code)->toBe('idempotency_conflict')
            ->and($exception->error->classification)->toBe(MondayOutcomeClassification::Retryable)
            ->and($exception->error->retryAfterSeconds)->toBe(7);
    }
});

test('phase 2e3c http client maps 429 with retry after to rate limited', function () {
    Http::fake([
        'api.monday.com/v2' => Http::response(['error' => 'slow down'], 429, ['Retry-After' => '15']),
    ]);

    try {
        phase2e3cBuildHttpClient()->createIntakeItem(phase2e3cSampleCreateRequest());
        expect(false)->toBeTrue('Expected MondayApiClientException');
    } catch (MondayApiClientException $exception) {
        expect($exception->error->code)->toBe('rate_limited')
            ->and($exception->error->classification)->toBe(MondayOutcomeClassification::RateLimited)
            ->and($exception->error->retryAfterSeconds)->toBe(15);
    }
});

test('phase 2e3c http client maps unauthorized responses to blocked configuration', function (int $status) {
    Http::fake([
        'api.monday.com/v2' => Http::response(['error' => 'nope'], $status),
    ]);

    try {
        phase2e3cBuildHttpClient()->createIntakeItem(phase2e3cSampleCreateRequest());
        expect(false)->toBeTrue('Expected MondayApiClientException');
    } catch (MondayApiClientException $exception) {
        expect($exception->error->code)->toBe('unauthorized')
            ->and($exception->error->classification)->toBe(MondayOutcomeClassification::BlockedConfiguration);
    }
})->with([401, 403]);

test('phase 2e3c http client maps graphql complexity to rate limited and invalid board to blocked', function (string $code, string $expectedCode, MondayOutcomeClassification $expectedClassification) {
    Http::fake([
        'api.monday.com/v2' => Http::response([
            'errors' => [[
                'message' => 'GraphQL failure',
                'extensions' => ['code' => $code],
            ]],
        ], 200),
    ]);

    try {
        phase2e3cBuildHttpClient()->createIntakeItem(phase2e3cSampleCreateRequest());
        expect(false)->toBeTrue('Expected MondayApiClientException');
    } catch (MondayApiClientException $exception) {
        expect($exception->error->code)->toBe($expectedCode)
            ->and($exception->error->classification)->toBe($expectedClassification);
    }
})->with([
    'complexity' => ['ComplexityException', 'rate_limited', MondayOutcomeClassification::RateLimited],
    'invalid board' => ['InvalidBoardException', 'blocked_configuration', MondayOutcomeClassification::BlockedConfiguration],
]);

test('phase 2e3c http client treats malformed json as permanent failure', function () {
    Http::fake([
        'api.monday.com/v2' => Http::response('not-json-at-all', 200, ['Content-Type' => 'application/json']),
    ]);

    try {
        phase2e3cBuildHttpClient()->createIntakeItem(phase2e3cSampleCreateRequest());
        expect(false)->toBeTrue('Expected MondayApiClientException');
    } catch (MondayApiClientException $exception) {
        expect($exception->error->code)->toBe('non_json_body')
            ->and($exception->error->classification)->toBe(MondayOutcomeClassification::PermanentFailure);
    }
});

test('phase 2e3c http client treats oversized response as permanent failure', function () {
    Http::fake([
        'api.monday.com/v2' => Http::response(str_repeat('x', 200), 200, ['Content-Type' => 'application/json']),
    ]);

    try {
        phase2e3cBuildHttpClient(maxResponseBytes: 50)->createIntakeItem(phase2e3cSampleCreateRequest());
        expect(false)->toBeTrue('Expected MondayApiClientException');
    } catch (MondayApiClientException $exception) {
        expect($exception->error->code)->toBe('oversized_response')
            ->and($exception->error->classification)->toBe(MondayOutcomeClassification::PermanentFailure);
    }
});

test('phase 2e3c http client maps timed out connection to uncertain timeout', function () {
    Http::fake(function (): never {
        throw new ConnectionException('cURL error 28: Operation timed out after 20000 milliseconds with 0 bytes received');
    });

    try {
        phase2e3cBuildHttpClient()->createIntakeItem(phase2e3cSampleCreateRequest());
        expect(false)->toBeTrue('Expected MondayApiClientException');
    } catch (MondayApiClientException $exception) {
        expect($exception->error->code)->toBe('uncertain_timeout')
            ->and($exception->error->classification)->toBe(MondayOutcomeClassification::UncertainOutcome);
    }
});

test('phase 2e3c http client inspect board maps groups and columns', function () {
    Http::fake([
        'api.monday.com/v2' => Http::response([
            'data' => [
                'boards' => [[
                    'id' => 'fake_board_100',
                    'name' => 'Intake Board',
                    'groups' => [
                        ['id' => 'fake_group_100', 'title' => 'New Leads'],
                    ],
                    'columns' => [
                        ['id' => 'text_integration_key', 'title' => 'Key', 'type' => 'text', 'settings_str' => null],
                        ['id' => 'status_intake', 'title' => 'Status', 'type' => 'status', 'settings_str' => json_encode(['labels' => ['New Intake', 'Done']])],
                    ],
                ]],
            ],
        ], 200),
    ]);

    $board = phase2e3cBuildHttpClient()->inspectBoard('fake_board_100');

    expect($board->id)->toBe('fake_board_100')
        ->and($board->name)->toBe('Intake Board')
        ->and($board->groups)->toHaveCount(1)
        ->and($board->groups[0]->id)->toBe('fake_group_100')
        ->and($board->columns)->toHaveCount(2)
        ->and($board->columns[1]->statusLabels)->toBe(['New Intake', 'Done']);
});

test('phase 2e3c http client find item by integration key handles zero one and ambiguous matches', function (string $scenario, int $expectedCount, bool $found, bool $ambiguous) {
    $items = match ($scenario) {
        'zero' => [],
        'one' => [[
            'id' => 'item_match_1',
            'name' => 'Matched',
            'url' => 'https://monday.test/pulses/item_match_1',
            'board' => ['id' => 'fake_board_100'],
        ]],
        'ambiguous' => [
            ['id' => 'item_a', 'name' => 'A', 'url' => null, 'board' => ['id' => 'fake_board_100']],
            ['id' => 'item_b', 'name' => 'B', 'url' => null, 'board' => ['id' => 'fake_board_100']],
        ],
        default => [],
    };

    Http::fake([
        'api.monday.com/v2' => Http::response([
            'data' => [
                'items_page_by_column_values' => [
                    'items' => $items,
                ],
            ],
        ], 200),
    ]);

    $result = phase2e3cBuildHttpClient()->findItemByIntegrationKey(
        boardId: 'fake_board_100',
        integrationKeyColumnId: 'text_integration_key',
        integrationKey: 'org:1:quote:2:rev:1',
    );

    expect($result->found)->toBe($found)
        ->and($result->ambiguous)->toBe($ambiguous)
        ->and($result->matchCount)->toBe($expectedCount);

    Http::assertSent(function ($httpRequest): bool {
        $variables = $httpRequest->data()['variables'] ?? [];

        return ($variables['limit'] ?? null) === 5;
    });
})->with([
    'zero matches' => ['zero', 0, false, false],
    'one match' => ['one', 1, true, false],
    'ambiguous matches' => ['ambiguous', 2, false, true],
]);

test('phase 2e3c http client is constructed with connect and request timeouts', function () {
    $client = phase2e3cBuildHttpClient();

    $reflection = new ReflectionClass($client);
    $connect = $reflection->getProperty('connectTimeoutSeconds');
    $connect->setAccessible(true);
    $timeout = $reflection->getProperty('timeoutSeconds');
    $timeout->setAccessible(true);

    expect($connect->getValue($client))->toBe(5)
        ->and($timeout->getValue($client))->toBe(20);
});

test('phase 2e3c http client blocks stray requests', function () {
    expect(fn () => Http::get('https://example.com/unexpected'))
        ->toThrow(StrayRequestException::class);
});
