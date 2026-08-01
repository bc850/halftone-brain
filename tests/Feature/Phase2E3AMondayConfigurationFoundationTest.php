<?php

use App\Enums\IntegrationLineDetailMode;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationProviderReceiptDiscoveryMethod;
use App\Enums\IntegrationRemoteResourceType;
use App\Enums\MondayColumnType;
use App\Enums\MondayIntakeLogicalKey;
use App\Models\IntegrationOutboxDelivery;
use App\Models\IntegrationProviderReceipt;
use App\Models\Organization;
use App\Models\OrganizationIntegrationSetting;
use App\Support\Integrations\Monday\AcceptedQuoteMondayMappingInput;
use App\Support\Integrations\Monday\Dto\MondayBoardMetadata;
use App\Support\Integrations\Monday\Dto\MondayColumnMetadata;
use App\Support\Integrations\Monday\Dto\MondayCreatedItemResult;
use App\Support\Integrations\Monday\Dto\MondayCreateItemRequest;
use App\Support\Integrations\Monday\Dto\MondayGroupMetadata;
use App\Support\Integrations\Monday\FakeMondayApiClient;
use App\Support\Integrations\Monday\MondayApiClientException;
use App\Support\Integrations\Monday\MondayApiVersion;
use App\Support\Integrations\Monday\MondayColumnMappingSet;
use App\Support\Integrations\Monday\MondayConsumerKeys;
use App\Support\Integrations\Monday\MondayItemNameTemplate;
use App\Support\Integrations\Outbox\IntegrationConsumerRegistry;
use App\Support\Integrations\Outbox\IntegrationErrorSanitizer;
use App\Support\Quotes\Acceptance\QuoteAcceptanceAtomicityContract;
use Database\Factories\OrganizationIntegrationSettingFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

const PHASE_2E3A_SETTINGS_MIGRATION = '2026_08_01_170343_create_organization_integration_settings_table';
const PHASE_2E3A_RECEIPTS_MIGRATION = '2026_08_01_170344_create_integration_provider_receipts_table';

function phase2e3aHasIndex(string $table, string $indexName, bool $unique = false): bool
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

function phase2e3aHasColumn(string $table, string $column): bool
{
    return Schema::hasColumn($table, $column);
}

test('phase 2e3a settings and receipts migrations create expected schema and roll back', function () {
    expect(Schema::hasTable('organization_integration_settings'))->toBeTrue()
        ->and(Schema::hasTable('integration_provider_receipts'))->toBeTrue()
        ->and(phase2e3aHasIndex('organization_integration_settings', 'ois_org_provider_uidx', true))->toBeTrue()
        ->and(phase2e3aHasIndex('integration_provider_receipts', 'ipr_delivery_provider_type_uidx', true))->toBeTrue()
        ->and(phase2e3aHasIndex('integration_provider_receipts', 'ipr_org_provider_remote_uidx', true))->toBeTrue()
        ->and(phase2e3aHasColumn('organization_integration_settings', 'board_id'))->toBeTrue()
        ->and(phase2e3aHasColumn('organization_integration_settings', 'api_token'))->toBeFalse()
        ->and(phase2e3aHasColumn('integration_provider_receipts', 'updated_at'))->toBeFalse()
        ->and(phase2e3aHasColumn('integration_provider_receipts', 'response_body'))->toBeFalse();

    Artisan::call('migrate:rollback', [
        '--path' => 'database/migrations/'.PHASE_2E3A_RECEIPTS_MIGRATION.'.php',
        '--force' => true,
    ]);
    expect(Schema::hasTable('integration_provider_receipts'))->toBeFalse()
        ->and(Schema::hasTable('organization_integration_settings'))->toBeTrue()
        ->and(Schema::hasTable('integration_outbox_deliveries'))->toBeTrue();

    Artisan::call('migrate:rollback', [
        '--path' => 'database/migrations/'.PHASE_2E3A_SETTINGS_MIGRATION.'.php',
        '--force' => true,
    ]);
    expect(Schema::hasTable('organization_integration_settings'))->toBeFalse()
        ->and(Schema::hasTable('integration_outbox_deliveries'))->toBeTrue();

    Artisan::call('migrate', [
        '--path' => 'database/migrations/'.PHASE_2E3A_SETTINGS_MIGRATION.'.php',
        '--force' => true,
    ]);
    Artisan::call('migrate', [
        '--path' => 'database/migrations/'.PHASE_2E3A_RECEIPTS_MIGRATION.'.php',
        '--force' => true,
    ]);

    expect(Schema::hasTable('organization_integration_settings'))->toBeTrue()
        ->and(Schema::hasTable('integration_provider_receipts'))->toBeTrue();
});

test('phase 2e3a organization integration settings defaults and guarded lifecycle fields', function () {
    $setting = OrganizationIntegrationSetting::factory()->create([
        'board_id' => null,
        'group_id' => null,
        'column_mapping_json' => null,
        'status_label_mappings_json' => null,
    ]);

    expect($setting->provider)->toBe(IntegrationProvider::Monday)
        ->and($setting->enabled)->toBeFalse()
        ->and($setting->api_version)->toBe(MondayApiVersion::PINNED)
        ->and($setting->item_name_template)->toBe(MondayItemNameTemplate::DEFAULT)
        ->and($setting->line_detail_mode)->toBe(IntegrationLineDetailMode::Summary)
        ->and($setting->lock_version)->toBe(1)
        ->and($setting->board_id)->toBeNull()
        ->and($setting->last_validated_at)->toBeNull();

    $setting->fill([
        'last_validation_status' => 'valid',
        'last_validation_error_code' => 'x',
        'lock_version' => 99,
    ]);

    expect($setting->isDirty('last_validation_status'))->toBeFalse()
        ->and($setting->isDirty('last_validation_error_code'))->toBeFalse()
        ->and($setting->isDirty('lock_version'))->toBeFalse();
});

test('phase 2e3a organization provider configuration is unique and tenant-safe', function () {
    $org = Organization::factory()->create();
    OrganizationIntegrationSetting::factory()->create([
        'organization_id' => $org->id,
        'parent_account_id' => $org->parent_account_id,
        'provider' => IntegrationProvider::Monday,
    ]);

    expect(fn () => OrganizationIntegrationSetting::factory()->create([
        'organization_id' => $org->id,
        'parent_account_id' => $org->parent_account_id,
        'provider' => IntegrationProvider::Monday,
    ]))->toThrow(QueryException::class);

    $other = Organization::factory()->create();

    expect(fn () => OrganizationIntegrationSetting::factory()->create([
        'organization_id' => $org->id,
        'parent_account_id' => $other->parent_account_id,
        'provider' => IntegrationProvider::Monday,
    ]))->toThrow(QueryException::class);
});

test('phase 2e3a provider receipts enforce uniqueness append-only and restrict deletes', function () {
    $delivery = IntegrationOutboxDelivery::factory()->create();
    $receipt = IntegrationProviderReceipt::factory()->create([
        'integration_outbox_delivery_id' => $delivery->id,
        'parent_account_id' => $delivery->parent_account_id,
        'organization_id' => $delivery->organization_id,
        'remote_id' => 'fake_item_unique_1',
    ]);

    expect($receipt->provider)->toBe(IntegrationProvider::Monday)
        ->and($receipt->remote_resource_type)->toBe(IntegrationRemoteResourceType::Item)
        ->and($receipt->discovery_method)->toBe(IntegrationProviderReceiptDiscoveryMethod::Created)
        ->and($receipt->idempotency_replayed)->toBeFalse();

    expect(fn () => IntegrationProviderReceipt::factory()->create([
        'integration_outbox_delivery_id' => $delivery->id,
        'parent_account_id' => $delivery->parent_account_id,
        'organization_id' => $delivery->organization_id,
        'remote_id' => 'fake_item_unique_2',
    ]))->toThrow(QueryException::class);

    $otherDelivery = IntegrationOutboxDelivery::factory()->create([
        'parent_account_id' => $delivery->parent_account_id,
        'organization_id' => $delivery->organization_id,
    ]);

    expect(fn () => IntegrationProviderReceipt::factory()->create([
        'integration_outbox_delivery_id' => $otherDelivery->id,
        'parent_account_id' => $delivery->parent_account_id,
        'organization_id' => $delivery->organization_id,
        'remote_id' => 'fake_item_unique_1',
    ]))->toThrow(QueryException::class);

    $foreignOrg = Organization::factory()->create();

    expect(fn () => IntegrationProviderReceipt::factory()->create([
        'integration_outbox_delivery_id' => $delivery->id,
        'parent_account_id' => $foreignOrg->parent_account_id,
        'organization_id' => $foreignOrg->id,
        'remote_id' => 'fake_item_cross_tenant',
    ]))->toThrow(QueryException::class);

    expect(fn () => $receipt->update(['remote_id' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $receipt->delete())->toThrow(LogicException::class);

    expect(fn () => $delivery->delete())->toThrow(QueryException::class);
});

test('phase 2e3a configuration mapping validation and template rules', function () {
    $set = MondayColumnMappingSet::fromArray(OrganizationIntegrationSettingFactory::defaultColumnMapping());
    $result = $set->validateForActivation(['intake_status' => 'New Intake']);

    expect($result->valid)->toBeTrue()->and($result->errors)->toBe([]);

    expect(fn () => MondayColumnMappingSet::fromArray([
        'requested_due_date' => [
            'column_id' => 'date_due',
            'expected_type' => MondayColumnType::Date->value,
            'required' => true,
            'enabled' => true,
        ],
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => MondayColumnMappingSet::fromArray([
        MondayIntakeLogicalKey::IntegrationKey->value => [
            'column_id' => 'status_wrong',
            'expected_type' => MondayColumnType::Status->value,
            'required' => true,
            'enabled' => true,
        ],
    ]))->toThrow(InvalidArgumentException::class);

    $duplicate = OrganizationIntegrationSettingFactory::defaultColumnMapping();
    $duplicate[MondayIntakeLogicalKey::Organization->value] = [
        'column_id' => 'text_integration_key',
        'expected_type' => MondayColumnType::Text->value,
        'required' => false,
        'enabled' => true,
    ];

    expect(fn () => MondayColumnMappingSet::fromArray($duplicate))->toThrow(InvalidArgumentException::class);

    expect(MondayItemNameTemplate::assertValid('{quote_number} — {company_name}'))->toBe('{quote_number} — {company_name}');

    expect(fn () => MondayItemNameTemplate::assertValid('{expiration_date} job'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => MondayItemNameTemplate::assertValid('{requested_due_date}'))->toThrow(InvalidArgumentException::class);

    expect(MondayIntakeLogicalKey::values())->not->toContain('requested_due_date')
        ->and(MondayIntakeLogicalKey::values())->not->toContain('expiration_date');

    $incomplete = MondayColumnMappingSet::fromArray([
        MondayIntakeLogicalKey::IntegrationKey->value => [
            'column_id' => 'text_integration_key',
            'expected_type' => MondayColumnType::Text->value,
            'required' => true,
            'enabled' => true,
        ],
    ]);

    expect($incomplete->validateForActivation(null)->valid)->toBeFalse();
});

test('phase 2e3a monday dtos fake client and sensitive data contracts', function () {
    Http::fake();
    Http::preventStrayRequests();

    $board = new MondayBoardMetadata(
        id: 'fake_board_1',
        name: 'Board',
        groups: [new MondayGroupMetadata('fake_group_1', 'Group')],
        columns: [new MondayColumnMetadata('text_integration_key', 'Key', MondayColumnType::Text)],
    );

    expect($board->id)->toBe('fake_board_1');

    $created = new MondayCreatedItemResult('fake_item_1', 'fake_board_1', 'https://monday.test/item/1');
    expect($created->idempotencyReplayed)->toBeFalse();

    $client = new FakeMondayApiClient;
    $client->seedDefaultBoard('fake_board_1', 'fake_group_1');

    $inspected = $client->inspectBoard('fake_board_1');
    expect($inspected->columns)->not->toBeEmpty()
        ->and($inspected->groups[0]->id)->toBe('fake_group_1');

    $request = new MondayCreateItemRequest(
        boardId: 'fake_board_1',
        groupId: 'fake_group_1',
        itemName: 'Q-100 — Acme',
        integrationKey: 'org:1:quote:1:rev:1',
        columnValues: [
            'text_quote_number' => 'Q-100',
            'text_company_name' => 'Acme',
        ],
    );

    $first = $client->createIntakeItem($request);
    $second = $client->createIntakeItem($request);

    expect($first->idempotencyReplayed)->toBeFalse()
        ->and($second->idempotencyReplayed)->toBeTrue()
        ->and($second->itemId)->toBe($first->itemId)
        ->and(count($client->recordedCreateRequests()))->toBe(2);

    $reconciled = $client->findItemByIntegrationKey('fake_board_1', 'text_integration_key', 'org:1:quote:1:rev:1');
    expect($reconciled->found)->toBeTrue()->and($reconciled->itemId)->toBe($first->itemId);

    $client->failNext('rate_limit');
    expect(fn () => $client->inspectBoard('fake_board_1'))->toThrow(MondayApiClientException::class);

    $client->failNext('unauthorized');
    expect(fn () => $client->inspectBoard('fake_board_1'))->toThrow(MondayApiClientException::class);

    $client->failNext('timeout');
    expect(fn () => $client->inspectBoard('fake_board_1'))->toThrow(MondayApiClientException::class);

    $client->failNext('graphql');
    expect(fn () => $client->inspectBoard('fake_board_1'))->toThrow(MondayApiClientException::class);

    $client->failNext('configuration');
    expect(fn () => $client->inspectBoard('fake_board_1'))->toThrow(MondayApiClientException::class);

    expect(fn () => $client->withApiToken('secret-token'))->toThrow(InvalidArgumentException::class);

    expect(fn () => AcceptedQuoteMondayMappingInput::fromApprovedParts([
        'quote_id' => 1,
        'quote_revision_id' => 1,
        'organization_id' => 1,
        'parent_account_id' => 1,
        'integration_key' => 'k',
        'quote_number' => 'Q-1',
        'revision_number' => 1,
        'company_name' => 'Acme',
        'accepted_date' => '2026-08-01',
        'pretax_total' => '100.00',
        'tax_total' => '0.00',
        'grand_total' => '100.00',
        'intake_status' => 'New Intake',
        'organization_integration_setting_id' => 1,
        'board_id' => 'fake_board_1',
        'item_name_template' => '{quote_number} — {company_name}',
        'line_detail_mode' => 'summary',
        'halftone_url' => 'https://halftone.test/quotes/1',
        'api_token' => 'should-fail',
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => new MondayCreateItemRequest(
        boardId: 'fake_board_1',
        groupId: null,
        itemName: 'x',
        integrationKey: 'k',
        columnValues: ['cost' => '1'],
    ))->toThrow(InvalidArgumentException::class);

    $input = AcceptedQuoteMondayMappingInput::fromApprovedParts([
        'quote_id' => 1,
        'quote_revision_id' => 2,
        'organization_id' => 3,
        'parent_account_id' => 4,
        'integration_key' => 'org:3:quote:1:rev:2',
        'quote_number' => 'Q-1',
        'revision_number' => 2,
        'company_name' => 'Acme',
        'accepted_date' => '2026-08-01',
        'pretax_total' => '100.00',
        'tax_total' => '8.00',
        'grand_total' => '108.00',
        'intake_status' => 'New Intake',
        'organization_integration_setting_id' => 9,
        'board_id' => 'fake_board_1',
        'group_id' => 'fake_group_1',
        'item_name_template' => '{quote_number} — {company_name}',
        'line_detail_mode' => 'summary',
        'halftone_url' => 'https://halftone.test/o/acme/quotes/1',
    ]);

    expect($input->identifiers())->toHaveKeys(['quote_id', 'integration_key'])
        ->and($input->customerSafeSnapshot())->not->toHaveKey('cost')
        ->and($input->integrationConfiguration())->not->toHaveKey('api_token')
        ->and($input->halftoneUrl)->toStartWith('https://');

    expect(Http::recorded())->toHaveCount(0);
});

test('phase 2e3a monday consumer key remains unregistered and provider projection allowlist is extended', function () {
    $registry = app(IntegrationConsumerRegistry::class);

    expect(MondayConsumerKeys::CREATE_INTAKE_ITEM)->toBe('monday.create_intake_item')
        ->and($registry->handler(
            QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE,
            MondayConsumerKeys::CREATE_INTAKE_ITEM,
        ))->toBeNull();

    $sanitizer = app(IntegrationErrorSanitizer::class);
    $ref = $sanitizer->providerReference([
        'provider' => 'monday',
        'resource_type' => 'item',
        'remote_id' => 'fake_item_9',
        'remote_board_id' => 'fake_board_9',
        'remote_url' => 'https://monday.test/boards/9/pulses/9',
        'provider_request_id' => 'req_9',
        'idempotency_replayed' => false,
        'api_version' => MondayApiVersion::PINNED,
        'authorization' => 'Bearer secret',
        'response_body' => '{"token":"nope"}',
    ]);

    expect($ref)->toMatchArray([
        'provider' => 'monday',
        'resource_type' => 'item',
        'remote_id' => 'fake_item_9',
        'remote_board_id' => 'fake_board_9',
        'remote_url' => 'https://monday.test/boards/9/pulses/9',
        'provider_request_id' => 'req_9',
        'idempotency_replayed' => false,
        'api_version' => MondayApiVersion::PINNED,
    ])->and($ref)->not->toHaveKey('authorization')
        ->and($ref)->not->toHaveKey('response_body');
});
