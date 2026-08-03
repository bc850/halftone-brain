<?php

namespace App\Support\Integrations\Monday;

use App\Enums\MondayColumnType;
use App\Support\Integrations\Monday\Credentials\MondayCredentials;
use App\Support\Integrations\Monday\Dto\MondayBoardMetadata;
use App\Support\Integrations\Monday\Dto\MondayColumnMetadata;
use App\Support\Integrations\Monday\Dto\MondayCreatedItemResult;
use App\Support\Integrations\Monday\Dto\MondayCreateItemRequest;
use App\Support\Integrations\Monday\Dto\MondayGroupMetadata;
use App\Support\Integrations\Monday\Dto\MondayReconciliationResult;
use App\Support\Integrations\Outbox\IntegrationErrorSanitizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Production Monday GraphQL HTTP client. Never logs request/response bodies or tokens.
 */
final class HttpMondayApiClient implements MondayApiClientInterface
{
    private const INSPECT_BOARD_QUERY = <<<'GRAPHQL'
query InspectBoard($boardIds: [ID!]) {
  boards(ids: $boardIds) {
    id
    name
    groups { id title }
    columns { id title type settings_str }
  }
}
GRAPHQL;

    private const CREATE_ITEM_MUTATION = <<<'GRAPHQL'
mutation CreateIntakeItem($boardId: ID!, $groupId: String, $itemName: String!, $columnValues: JSON!) {
  create_item(board_id: $boardId, group_id: $groupId, item_name: $itemName, column_values: $columnValues) {
    id
    name
    url
    board { id }
  }
}
GRAPHQL;

    private const FIND_BY_COLUMN_QUERY = <<<'GRAPHQL'
query FindByIntegrationKey($boardId: ID!, $limit: Int!, $columns: [ItemsPageByColumnValuesQuery!]!) {
  items_page_by_column_values(board_id: $boardId, limit: $limit, columns: $columns) {
    items { id name url board { id } }
  }
}
GRAPHQL;

    public function __construct(
        private MondayCredentials $credentials,
        private MondayErrorClassifier $classifier,
        private IntegrationErrorSanitizer $sanitizer,
        private string $apiUrl,
        private string $apiVersion,
        private int $connectTimeoutSeconds,
        private int $timeoutSeconds,
        private int $maxResponseBytes,
    ) {}

    public function inspectBoard(string $boardId): MondayBoardMetadata
    {
        $payload = $this->request(
            query: self::INSPECT_BOARD_QUERY,
            variables: ['boardIds' => [$boardId]],
            idempotencyKey: null,
        );

        $boards = $payload['data']['boards'] ?? null;
        if (! is_array($boards) || $boards === []) {
            throw MondayApiClientException::fromError($this->classifier->fromGraphqlErrorList([
                ['extensions' => ['code' => 'ResourceNotFoundException']],
            ]));
        }

        $board = $boards[0];
        if (! is_array($board) || ! isset($board['id'], $board['name'])) {
            throw MondayApiClientException::fromError($this->classifier->malformedResponse());
        }

        $groups = [];
        foreach (($board['groups'] ?? []) as $group) {
            if (! is_array($group) || ! isset($group['id'], $group['title'])) {
                continue;
            }
            $groups[] = new MondayGroupMetadata((string) $group['id'], (string) $group['title']);
        }

        $columns = [];
        foreach (($board['columns'] ?? []) as $column) {
            if (! is_array($column) || ! isset($column['id'], $column['title'], $column['type'])) {
                continue;
            }

            $type = MondayColumnType::tryFrom((string) $column['type']);
            if ($type === null) {
                continue;
            }

            $labels = $this->statusLabelsFromSettings(isset($column['settings_str']) ? (string) $column['settings_str'] : null);
            $columns[] = new MondayColumnMetadata(
                id: (string) $column['id'],
                title: (string) $column['title'],
                type: $type,
                statusLabels: $labels,
            );
        }

        return new MondayBoardMetadata(
            id: (string) $board['id'],
            name: (string) $board['name'],
            groups: $groups,
            columns: $columns,
        );
    }

    public function createIntakeItem(MondayCreateItemRequest $request): MondayCreatedItemResult
    {
        $columnValuesJson = json_encode($request->columnValues, JSON_THROW_ON_ERROR);

        $payload = $this->request(
            query: self::CREATE_ITEM_MUTATION,
            variables: [
                'boardId' => $request->boardId,
                'groupId' => $request->groupId,
                'itemName' => $request->itemName,
                'columnValues' => $columnValuesJson,
            ],
            idempotencyKey: $request->resolvedIdempotencyKey(),
            captureIdempotencyReplay: true,
        );

        $item = $payload['data']['create_item'] ?? null;
        if (! is_array($item) || ! isset($item['id'])) {
            throw MondayApiClientException::fromError($this->classifier->malformedResponse());
        }

        $boardId = isset($item['board']['id']) ? (string) $item['board']['id'] : $request->boardId;

        return new MondayCreatedItemResult(
            itemId: (string) $item['id'],
            boardId: $boardId,
            itemUrl: isset($item['url']) ? (string) $item['url'] : null,
            idempotencyReplayed: (bool) ($payload['_idempotency_replayed'] ?? false),
            providerRequestId: isset($payload['_provider_request_id']) ? (string) $payload['_provider_request_id'] : null,
            apiVersion: $request->apiVersion !== '' ? $request->apiVersion : $this->apiVersion,
        );
    }

    public function findItemByIntegrationKey(
        string $boardId,
        string $integrationKeyColumnId,
        string $integrationKey,
    ): MondayReconciliationResult {
        $payload = $this->request(
            query: self::FIND_BY_COLUMN_QUERY,
            variables: [
                'boardId' => $boardId,
                'limit' => 5,
                'columns' => [[
                    'column_id' => $integrationKeyColumnId,
                    'column_values' => [$integrationKey],
                ]],
            ],
            idempotencyKey: null,
        );

        $items = $payload['data']['items_page_by_column_values']['items'] ?? null;
        if (! is_array($items)) {
            throw MondayApiClientException::fromError($this->classifier->malformedResponse());
        }

        $count = count($items);
        if ($count === 0) {
            return MondayReconciliationResult::none($integrationKey);
        }

        if ($count > 1) {
            return MondayReconciliationResult::ambiguous($count, $integrationKey);
        }

        $item = $items[0];
        if (! is_array($item) || ! isset($item['id'])) {
            throw MondayApiClientException::fromError($this->classifier->malformedResponse());
        }

        return MondayReconciliationResult::one(
            itemId: (string) $item['id'],
            boardId: isset($item['board']['id']) ? (string) $item['board']['id'] : $boardId,
            itemUrl: isset($item['url']) ? (string) $item['url'] : null,
            integrationKey: $integrationKey,
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function request(
        string $query,
        array $variables,
        ?string $idempotencyKey,
        bool $captureIdempotencyReplay = false,
    ): array {
        $headers = [
            'Authorization' => $this->credentials->authorizationHeaderValue(),
            'Content-Type' => 'application/json',
            'API-Version' => $this->apiVersion,
        ];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $transmitted = false;

        try {
            $pending = Http::withHeaders($headers)
                ->connectTimeout($this->connectTimeoutSeconds)
                ->timeout($this->timeoutSeconds)
                ->acceptJson();

            $response = $pending->post($this->apiUrl, [
                'query' => $query,
                'variables' => $variables,
            ]);
            $transmitted = true;
        } catch (ConnectionException $exception) {
            $message = strtolower($exception->getMessage());
            $possiblePostTransmission = str_contains($message, 'timed out')
                || str_contains($message, 'timeout')
                || str_contains($message, 'curl error 28');

            throw MondayApiClientException::fromError(
                $this->classifier->fromTransport($exception, $possiblePostTransmission),
            );
        } catch (Throwable $exception) {
            throw MondayApiClientException::fromError(
                $this->classifier->fromTransport($exception, $transmitted),
            );
        }

        $body = $response->body();
        if (strlen($body) > $this->maxResponseBytes) {
            throw MondayApiClientException::fromError($this->classifier->oversizedResponse());
        }

        if ($response->header('Content-Type') && ! str_contains(strtolower($response->header('Content-Type')), 'json')) {
            // Monday returns JSON; non-JSON is permanent.
            if ($body === '' || json_decode($body, true) === null) {
                throw MondayApiClientException::fromError($this->classifier->malformedResponse('non_json_body'));
            }
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw MondayApiClientException::fromError($this->classifier->malformedResponse('non_json_body'));
        }

        if ($response->status() !== 200 || (isset($json['errors']) && is_array($json['errors']) && $json['errors'] !== [])) {
            throw MondayApiClientException::fromError($this->classifier->fromHttpResponse($response));
        }

        if ($captureIdempotencyReplay) {
            $replayed = strtolower((string) $response->header('Idempotency-Replayed')) === 'true';
            $json['_idempotency_replayed'] = $replayed;
            $requestId = $response->header('X-Request-Id') ?: $response->header('x-request-id');
            if ($requestId !== '') {
                $json['_provider_request_id'] = $this->sanitizer->message($requestId);
            }
        }

        return $json;
    }

    /**
     * @return list<string>
     */
    private function statusLabelsFromSettings(?string $settingsStr): array
    {
        if ($settingsStr === null || trim($settingsStr) === '') {
            return [];
        }

        $decoded = json_decode($settingsStr, true);
        if (! is_array($decoded)) {
            return [];
        }

        $labels = [];
        $labelsMap = $decoded['labels'] ?? null;
        if (is_array($labelsMap)) {
            foreach ($labelsMap as $label) {
                if (is_string($label) && $label !== '') {
                    $labels[] = $label;
                }
            }
        }

        return array_values(array_unique($labels));
    }
}
