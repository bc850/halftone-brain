<?php

namespace App\Support\Integrations\Monday;

use App\Enums\MondayColumnType;
use App\Support\Integrations\Monday\Dto\MondayBoardMetadata;
use App\Support\Integrations\Monday\Dto\MondayColumnMetadata;
use App\Support\Integrations\Monday\Dto\MondayCreatedItemResult;
use App\Support\Integrations\Monday\Dto\MondayCreateItemRequest;
use App\Support\Integrations\Monday\Dto\MondayGroupMetadata;
use App\Support\Integrations\Monday\Dto\MondayReconciliationResult;
use InvalidArgumentException;

/**
 * In-memory Monday API fake for isolated tests and rehearsal.
 *
 * TEST-ONLY: performs no network I/O, never accepts or stores API tokens, and
 * must not be bound as the production Monday client in normal application runtime.
 */
final class FakeMondayApiClient implements MondayApiClientInterface
{
    /**
     * @var array<string, MondayBoardMetadata>
     */
    private array $boards = [];

    /**
     * @var list<MondayCreateItemRequest>
     */
    private array $createRequests = [];

    /**
     * @var array<string, MondayCreatedItemResult>
     */
    private array $itemsByIntegrationKey = [];

    private int $nextItemSequence = 1000;

    private ?string $nextFailure = null;

    public function seedDefaultBoard(string $boardId = 'fake_board_100', string $groupId = 'fake_group_100'): MondayBoardMetadata
    {
        $board = new MondayBoardMetadata(
            id: $boardId,
            name: 'Fake Intake Board',
            groups: [
                new MondayGroupMetadata($groupId, 'Fake Intake Group'),
            ],
            columns: [
                new MondayColumnMetadata('text_integration_key', 'Integration Key', MondayColumnType::Text),
                new MondayColumnMetadata('text_quote_number', 'Quote Number', MondayColumnType::Text),
                new MondayColumnMetadata('text_company_name', 'Company', MondayColumnType::Text),
                new MondayColumnMetadata('date_accepted', 'Accepted', MondayColumnType::Date),
                new MondayColumnMetadata('numbers_grand_total', 'Grand Total', MondayColumnType::Numbers),
                new MondayColumnMetadata('link_halftone', 'Halftone URL', MondayColumnType::Link),
                new MondayColumnMetadata('status_intake', 'Intake Status', MondayColumnType::Status),
            ],
        );

        $this->boards[$boardId] = $board;

        return $board;
    }

    public function seedBoard(MondayBoardMetadata $board): void
    {
        $this->boards[$board->id] = $board;
    }

    public function failNext(string $failure): void
    {
        $allowed = ['rate_limit', 'graphql', 'timeout', 'unauthorized', 'configuration'];

        if (! in_array($failure, $allowed, true)) {
            throw new InvalidArgumentException("Unsupported fake Monday failure [{$failure}].");
        }

        $this->nextFailure = $failure;
    }

    public function inspectBoard(string $boardId): MondayBoardMetadata
    {
        $this->assertNoTokenArgument($boardId);
        $this->maybeThrowConfiguredFailure();

        if (! isset($this->boards[$boardId])) {
            throw MondayApiClientException::configuration("Board [{$boardId}] was not found in the fake client.");
        }

        return $this->boards[$boardId];
    }

    public function createIntakeItem(MondayCreateItemRequest $request): MondayCreatedItemResult
    {
        $this->maybeThrowConfiguredFailure();

        $existing = $this->itemsByIntegrationKey[$request->integrationKey] ?? null;

        if ($existing !== null) {
            $replayed = new MondayCreatedItemResult(
                itemId: $existing->itemId,
                boardId: $existing->boardId,
                itemUrl: $existing->itemUrl,
                idempotencyReplayed: true,
                providerRequestId: 'fake_replay_'.$existing->itemId,
                apiVersion: $request->apiVersion,
            );
            $this->createRequests[] = $request;

            return $replayed;
        }

        $this->createRequests[] = $request;
        $itemId = 'fake_item_'.$this->nextItemSequence++;
        $result = new MondayCreatedItemResult(
            itemId: $itemId,
            boardId: $request->boardId,
            itemUrl: 'https://monday.test/boards/'.$request->boardId.'/pulses/'.$itemId,
            idempotencyReplayed: false,
            providerRequestId: 'fake_req_'.$itemId,
            apiVersion: $request->apiVersion,
        );

        $this->itemsByIntegrationKey[$request->integrationKey] = $result;

        return $result;
    }

    public function findItemByIntegrationKey(string $boardId, string $integrationKeyColumnId, string $integrationKey): MondayReconciliationResult
    {
        $this->assertNoTokenArgument($boardId);
        $this->assertNoTokenArgument($integrationKeyColumnId);
        $this->assertNoTokenArgument($integrationKey);
        $this->maybeThrowConfiguredFailure();

        $existing = $this->itemsByIntegrationKey[$integrationKey] ?? null;

        if ($existing === null || $existing->boardId !== $boardId) {
            return new MondayReconciliationResult(found: false);
        }

        return new MondayReconciliationResult(
            found: true,
            itemId: $existing->itemId,
            boardId: $existing->boardId,
            itemUrl: $existing->itemUrl,
            integrationKey: $integrationKey,
        );
    }

    /**
     * @return list<MondayCreateItemRequest>
     */
    public function recordedCreateRequests(): array
    {
        return $this->createRequests;
    }

    /**
     * @return array<string, MondayCreatedItemResult>
     */
    public function itemsByIntegrationKey(): array
    {
        return $this->itemsByIntegrationKey;
    }

    /**
     * Explicitly reject token-like method misuse. Fake never stores credentials.
     */
    public function withApiToken(string $token): never
    {
        throw new InvalidArgumentException('FakeMondayApiClient never accepts API tokens.');
    }

    private function maybeThrowConfiguredFailure(): void
    {
        if ($this->nextFailure === null) {
            return;
        }

        $failure = $this->nextFailure;
        $this->nextFailure = null;

        match ($failure) {
            'rate_limit' => throw MondayApiClientException::rateLimited(),
            'graphql' => throw MondayApiClientException::graphqlError(),
            'timeout' => throw MondayApiClientException::timeout(),
            'unauthorized' => throw MondayApiClientException::unauthorized(),
            'configuration' => throw MondayApiClientException::configuration(),
            default => throw new InvalidArgumentException("Unsupported fake Monday failure [{$failure}]."),
        };
    }

    private function assertNoTokenArgument(string $value): void
    {
        if (MondaySensitivePayloadGuard::isForbiddenKey($value) || str_starts_with(strtolower($value), 'bearer ')) {
            throw new InvalidArgumentException('FakeMondayApiClient arguments must not look like credentials.');
        }
    }
}
