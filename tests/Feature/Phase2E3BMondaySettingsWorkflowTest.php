<?php

use App\Enums\IntegrationOutboxDeliveryStatus;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationValidationStatus;
use App\Enums\MondayColumnType;
use App\Enums\MondayIntakeLogicalKey;
use App\Models\AuditEvent;
use App\Models\IntegrationOutbox;
use App\Models\IntegrationOutboxDelivery;
use App\Models\IntegrationProviderReceipt;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationIntegrationSetting;
use App\Models\ParentAccount;
use App\Models\ParentAccountMembership;
use App\Models\User;
use App\Support\Integrations\Monday\Dto\MondayBoardMetadata;
use App\Support\Integrations\Monday\Dto\MondayColumnMetadata;
use App\Support\Integrations\Monday\Dto\MondayGroupMetadata;
use App\Support\Integrations\Monday\FakeMondayApiClient;
use App\Support\Integrations\Monday\MondayApiClientInterface;
use App\Support\Integrations\Monday\MondayApiVersion;
use App\Support\Integrations\Monday\MondayConsumerKeys;
use App\Support\Integrations\Monday\MondayOrganizationSettingsService;
use App\Support\Integrations\Monday\UnavailableMondayApiClient;
use App\Support\Integrations\Outbox\IntegrationConsumerRegistry;
use App\Support\Quotes\Acceptance\QuoteAcceptanceAtomicityContract;
use App\Support\Tenancy\PermissionResolver;
use App\Support\Tenancy\TenantContext;
use Database\Factories\OrganizationIntegrationSettingFactory;
use Illuminate\Support\Facades\Http;
use Tests\Support\Phase2E3CHelpers;

beforeEach(function (): void {
    $this->withoutVite();
});

/**
 * @return array<string, mixed>
 */
function phase2e3bValidPayload(array $overrides = []): array
{
    $required = [];

    foreach (OrganizationIntegrationSettingFactory::defaultColumnMapping() as $key => $entry) {
        $required[$key] = [
            'column_id' => $entry['column_id'],
            'expected_type' => $entry['expected_type'],
        ];
    }

    return array_replace_recursive([
        'board_id' => 'fake_board_100',
        'group_id' => 'fake_group_100',
        'api_version' => MondayApiVersion::PINNED,
        'item_name_template' => '{quote_number} — {company_name}',
        'line_detail_mode' => 'summary',
        'intake_status_label' => 'New Intake',
        'required_mappings' => $required,
        'optional_mappings' => [
            MondayIntakeLogicalKey::Organization->value => [
                'enabled' => true,
                'column_id' => 'text_organization',
                'expected_type' => MondayColumnType::Text->value,
            ],
        ],
    ], $overrides);
}

/**
 * @param  array{user: User, parent: ParentAccount, organization: Organization, membership: Membership, parentMembership: ParentAccountMembership|null}  $ctx
 */
function phase2e3bEstablishTenant(array $ctx): void
{
    TenantContext::clear();

    $resolver = app(PermissionResolver::class);

    TenantContext::establish(
        userId: $ctx['user']->id,
        parentAccountId: $ctx['parent']->id,
        organizationId: $ctx['organization']->id,
        parentMembershipId: $ctx['parentMembership']?->id,
        organizationMembershipId: $ctx['membership']->id,
        organization: $ctx['organization'],
        parentPermissions: $resolver->forParentMembership($ctx['parentMembership']),
        organizationPermissions: $resolver->forOrganizationMembership($ctx['membership']),
    );
}

function phase2e3bBindFakeClient(?FakeMondayApiClient $client = null): FakeMondayApiClient
{
    $fake = $client ?? new FakeMondayApiClient;
    $fake->seedDefaultBoard();
    $fake->seedBoard(new MondayBoardMetadata(
        id: 'fake_board_100',
        name: 'Intake',
        groups: [new MondayGroupMetadata('fake_group_100', 'Group')],
        columns: [
            new MondayColumnMetadata('text_integration_key', 'Key', MondayColumnType::Text),
            new MondayColumnMetadata('text_quote_number', 'Quote', MondayColumnType::Text),
            new MondayColumnMetadata('text_company_name', 'Company', MondayColumnType::Text),
            new MondayColumnMetadata('date_accepted', 'Accepted', MondayColumnType::Date),
            new MondayColumnMetadata('numbers_grand_total', 'Total', MondayColumnType::Numbers),
            new MondayColumnMetadata('link_halftone', 'URL', MondayColumnType::Link),
            new MondayColumnMetadata('status_intake', 'Status', MondayColumnType::Status, ['New Intake', 'Done']),
            new MondayColumnMetadata('text_organization', 'Org', MondayColumnType::Text),
        ],
    ));

    app()->instance(MondayApiClientInterface::class, $fake);
    Phase2E3CHelpers::bindTestCredentials();

    return $fake;
}

test('phase 2e3b empty settings show is tenant scoped and owner can manage', function () {
    $ctx = createTenantUser('owner');

    $this->actingAs($ctx['user'])
        ->get(route('org.integrations.settings.monday.show', $ctx['organization']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('integrations/MondaySettings')
            ->where('settings', null)
            ->where('can_manage', true)
            ->where('can_validate', false)
            ->missing('settings.api_token')
            ->etc());
});

test('phase 2e3b sales manager is read only and cannot mutate', function () {
    $ctx = createTenantUser('sales_manager');
    $settings = OrganizationIntegrationSetting::factory()->create([
        'organization_id' => $ctx['organization']->id,
        'parent_account_id' => $ctx['parent']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->get(route('org.integrations.settings.monday.show', $ctx['organization']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('can_manage', false)
            ->where('can_validate', false)
            ->where('settings.id', $settings->id)
            ->etc());

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.store', $ctx['organization']), phase2e3bValidPayload())
        ->assertForbidden();

    $this->actingAs($ctx['user'])
        ->put(route('org.integrations.settings.monday.update', [$ctx['organization'], $settings]), phase2e3bValidPayload([
            'expected_lock_version' => 1,
        ]))
        ->assertForbidden();

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.validate', [$ctx['organization'], $settings]), [
            'expected_lock_version' => 1,
        ])
        ->assertForbidden();

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.enable', [$ctx['organization'], $settings]), [
            'expected_lock_version' => 1,
        ])
        ->assertForbidden();
});

test('phase 2e3b permission matrix for view manage and validate', function (string $role, bool $canView, bool $canManage) {
    $ctx = createTenantUser($role);

    $show = $this->actingAs($ctx['user'])
        ->get(route('org.integrations.settings.monday.show', $ctx['organization']));

    if ($canView) {
        $show->assertSuccessful();
    } else {
        $show->assertForbidden();
    }

    $store = $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.store', $ctx['organization']), phase2e3bValidPayload());

    if ($canManage) {
        $store->assertRedirect(route('org.integrations.settings.monday.show', $ctx['organization']));
        expect(OrganizationIntegrationSetting::query()->count())->toBe(1);
    } else {
        $store->assertForbidden();
        expect(OrganizationIntegrationSetting::query()->count())->toBe(0);
    }
})->with([
    'owner' => ['owner', true, true],
    'admin' => ['admin', true, true],
    'sales_manager' => ['sales_manager', true, false],
    'salesperson' => ['salesperson', false, false],
    'finance' => ['finance', false, false],
    'project_manager' => ['project_manager', false, false],
]);

test('phase 2e3b cross organization settings access returns 404', function () {
    $ownerA = createTenantUser('owner');
    $ownerB = createTenantUser('owner');

    $settingsB = OrganizationIntegrationSetting::factory()->create([
        'organization_id' => $ownerB['organization']->id,
        'parent_account_id' => $ownerB['parent']->id,
    ]);

    $this->actingAs($ownerA['user'])
        ->get(route('org.integrations.settings.monday.show', $ownerB['organization']))
        ->assertNotFound();

    $this->actingAs($ownerA['user'])
        ->put(route('org.integrations.settings.monday.update', [$ownerA['organization'], $settingsB]), phase2e3bValidPayload([
            'expected_lock_version' => 1,
        ]))
        ->assertNotFound();

    $this->actingAs($ownerA['user'])
        ->post(route('org.integrations.settings.monday.validate', [$ownerA['organization'], $settingsB]), [
            'expected_lock_version' => 1,
        ])
        ->assertNotFound();
});

test('phase 2e3b store creates settings strips tenant fields rejects secrets and raw json', function () {
    Http::fake();
    $ctx = createTenantUser('owner');

    $payload = phase2e3bValidPayload([
        'organization_id' => 999,
        'parent_account_id' => 999,
        'provider' => 'monday',
        'enabled' => true,
        'api_token' => 'secret-token',
        'column_mapping_json' => ['integration_key' => ['column_id' => 'x']],
        'status_label_mappings_json' => ['intake_status' => 'Hack'],
        'last_validation_status' => 'valid',
        'token' => 'nope',
    ]);

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.store', $ctx['organization']), $payload)
        ->assertSessionHasErrors();

    unset(
        $payload['organization_id'],
        $payload['parent_account_id'],
        $payload['provider'],
        $payload['enabled'],
        $payload['api_token'],
        $payload['column_mapping_json'],
        $payload['status_label_mappings_json'],
        $payload['last_validation_status'],
        $payload['token'],
    );

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.store', $ctx['organization']), $payload)
        ->assertRedirect(route('org.integrations.settings.monday.show', $ctx['organization']));

    $settings = OrganizationIntegrationSetting::query()->sole();

    expect($settings->organization_id)->toBe($ctx['organization']->id)
        ->and($settings->parent_account_id)->toBe($ctx['parent']->id)
        ->and($settings->provider)->toBe(IntegrationProvider::Monday)
        ->and($settings->enabled)->toBeFalse()
        ->and($settings->last_validation_status)->toBe(IntegrationValidationStatus::NeverValidated)
        ->and($settings->column_mapping_json)->toHaveKey(MondayIntakeLogicalKey::IntegrationKey->value)
        ->and($settings->column_mapping_json)->not->toHaveKey('requested_due_date')
        ->and($settings->column_mapping_json)->not->toHaveKey('expiration_date')
        ->and(AuditEvent::query()->where('action', MondayOrganizationSettingsService::AUDIT_CREATED)->count())->toBe(1)
        ->and(Http::recorded())->toHaveCount(0);
});

test('phase 2e3b organization provider uniqueness and unknown keys placeholders', function () {
    $ctx = createTenantUser('owner');

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.store', $ctx['organization']), phase2e3bValidPayload())
        ->assertRedirect();

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.store', $ctx['organization']), phase2e3bValidPayload())
        ->assertSessionHasErrors('settings');

    $badTemplate = phase2e3bValidPayload([
        'item_name_template' => '{quote_number} {unknown_field}',
    ]);

    $settings = OrganizationIntegrationSetting::query()->sole();

    $this->actingAs($ctx['user'])
        ->put(route('org.integrations.settings.monday.update', [$ctx['organization'], $settings]), array_merge($badTemplate, [
            'expected_lock_version' => $settings->lock_version,
        ]))
        ->assertSessionHasErrors();

    $unknownKey = phase2e3bValidPayload();
    $unknownKey['required_mappings']['requested_due_date'] = [
        'column_id' => 'date_due',
        'expected_type' => MondayColumnType::Date->value,
    ];

    $this->actingAs($ctx['user'])
        ->put(route('org.integrations.settings.monday.update', [$ctx['organization'], $settings->fresh()]), array_merge($unknownKey, [
            'expected_lock_version' => $settings->fresh()->lock_version,
        ]))
        ->assertSessionHasErrors();
});

test('phase 2e3b material update clears validation disables and stale lock returns 409', function () {
    Http::fake();
    $ctx = createTenantUser('owner');
    $fake = phase2e3bBindFakeClient();

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.store', $ctx['organization']), phase2e3bValidPayload())
        ->assertRedirect();

    $settings = OrganizationIntegrationSetting::query()->sole();

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.validate', [$ctx['organization'], $settings]), [
            'expected_lock_version' => $settings->lock_version,
        ])
        ->assertRedirect();

    $settings->refresh();
    expect($settings->last_validation_status)->toBe(IntegrationValidationStatus::Valid)
        ->and($settings->enabled)->toBeFalse()
        ->and($fake->recordedCreateRequests())->toHaveCount(0);

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.enable', [$ctx['organization'], $settings]), [
            'expected_lock_version' => $settings->lock_version,
        ])
        ->assertRedirect();

    $settings->refresh();
    expect($settings->enabled)->toBeTrue();

    $this->actingAs($ctx['user'])
        ->put(route('org.integrations.settings.monday.update', [$ctx['organization'], $settings]), phase2e3bValidPayload([
            'board_id' => 'fake_board_100',
            'group_id' => 'fake_group_100',
            'expected_lock_version' => $settings->lock_version,
            'required_mappings' => array_replace(
                phase2e3bValidPayload()['required_mappings'],
                [
                    MondayIntakeLogicalKey::QuoteNumber->value => [
                        'column_id' => 'text_quote_number_changed',
                        'expected_type' => MondayColumnType::Text->value,
                    ],
                ],
            ),
        ]))
        ->assertRedirect();

    $settings->refresh();
    expect($settings->enabled)->toBeFalse()
        ->and($settings->last_validation_status)->toBe(IntegrationValidationStatus::NeverValidated)
        ->and($settings->last_validated_at)->toBeNull()
        ->and($settings->last_validation_error_code)->toBeNull();

    $this->actingAs($ctx['user'])
        ->put(route('org.integrations.settings.monday.update', [$ctx['organization'], $settings]), phase2e3bValidPayload([
            'expected_lock_version' => 1,
        ]))
        ->assertStatus(409);

    expect(Http::recorded())->toHaveCount(0);
});

test('phase 2e3b cannot enable without fresh valid validation and validate does not auto enable', function () {
    $ctx = createTenantUser('owner');
    phase2e3bBindFakeClient();

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.store', $ctx['organization']), phase2e3bValidPayload())
        ->assertRedirect();

    $settings = OrganizationIntegrationSetting::query()->sole();

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.enable', [$ctx['organization'], $settings]), [
            'expected_lock_version' => $settings->lock_version,
        ])
        ->assertSessionHasErrors('settings');

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.validate', [$ctx['organization'], $settings]), [
            'expected_lock_version' => $settings->lock_version,
        ])
        ->assertRedirect();

    $settings->refresh();
    expect($settings->last_validation_status)->toBe(IntegrationValidationStatus::Valid)
        ->and($settings->enabled)->toBeFalse();

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.enable', [$ctx['organization'], $settings]), [
            'expected_lock_version' => $settings->lock_version,
        ])
        ->assertRedirect();

    expect($settings->fresh()->enabled)->toBeTrue();
});

test('phase 2e3b disable preserves configuration and validation result', function () {
    $ctx = createTenantUser('owner');
    phase2e3bBindFakeClient();

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.store', $ctx['organization']), phase2e3bValidPayload())
        ->assertRedirect();

    $settings = OrganizationIntegrationSetting::query()->sole();

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.validate', [$ctx['organization'], $settings]), [
            'expected_lock_version' => $settings->lock_version,
        ]);

    $settings->refresh();

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.enable', [$ctx['organization'], $settings]), [
            'expected_lock_version' => $settings->lock_version,
        ]);

    $beforeDisable = $settings->fresh();

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.disable', [$ctx['organization'], $beforeDisable]), [
            'expected_lock_version' => $beforeDisable->lock_version,
        ])
        ->assertRedirect();

    $disabled = $beforeDisable->fresh();

    expect($disabled->enabled)->toBeFalse()
        ->and($disabled->board_id)->toBe($beforeDisable->board_id)
        ->and($disabled->column_mapping_json)->toEqual($beforeDisable->column_mapping_json)
        ->and($disabled->last_validation_status)->toBe(IntegrationValidationStatus::Valid)
        ->and(AuditEvent::query()->where('action', MondayOrganizationSettingsService::AUDIT_DISABLED)->count())->toBe(1);
});

test('phase 2e3b runtime client not configured fails safely without http', function () {
    Http::fake();
    $ctx = createTenantUser('owner');

    expect(app()->bound(MondayApiClientInterface::class))->toBeTrue()
        ->and(app(MondayApiClientInterface::class))->toBeInstanceOf(UnavailableMondayApiClient::class);

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.store', $ctx['organization']), phase2e3bValidPayload())
        ->assertRedirect();

    $settings = OrganizationIntegrationSetting::query()->sole();

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.validate', [$ctx['organization'], $settings]), [
            'expected_lock_version' => $settings->lock_version,
        ])
        ->assertRedirect();

    $settings->refresh();

    expect($settings->last_validation_status)->toBe(IntegrationValidationStatus::ClientNotConfigured)
        ->and($settings->enabled)->toBeFalse()
        ->and($settings->last_validation_error_code)->toBe('client_not_configured')
        ->and($settings->last_validation_error_message)->not->toContain('token')
        ->and(Http::recorded())->toHaveCount(0)
        ->and(AuditEvent::query()->where('action', MondayOrganizationSettingsService::AUDIT_VALIDATED)->count())->toBe(1);
});

test('phase 2e3b fake client validation failure modes create no items', function (string $mode) {
    Http::fake();
    $ctx = createTenantUser('owner');
    $fake = new FakeMondayApiClient;

    $board = new MondayBoardMetadata(
        id: 'fake_board_100',
        name: 'Intake',
        groups: [new MondayGroupMetadata('fake_group_100', 'Group')],
        columns: [
            new MondayColumnMetadata('text_integration_key', 'Key', MondayColumnType::Text),
            new MondayColumnMetadata('text_quote_number', 'Quote', MondayColumnType::Text),
            new MondayColumnMetadata('text_company_name', 'Company', MondayColumnType::Text),
            new MondayColumnMetadata('date_accepted', 'Accepted', MondayColumnType::Date),
            new MondayColumnMetadata('numbers_grand_total', 'Total', MondayColumnType::Numbers),
            new MondayColumnMetadata('link_halftone', 'URL', MondayColumnType::Link),
            new MondayColumnMetadata('status_intake', 'Status', MondayColumnType::Status, ['New Intake']),
            new MondayColumnMetadata('text_organization', 'Org', MondayColumnType::Text),
        ],
    );

    $payload = phase2e3bValidPayload();

    if ($mode === 'missing_board') {
        $payload['board_id'] = 'missing_board';
        $fake->seedBoard($board);
    } elseif ($mode === 'missing_group') {
        $fake->seedBoard(new MondayBoardMetadata(
            id: 'fake_board_100',
            name: 'Intake',
            groups: [new MondayGroupMetadata('other_group', 'Other')],
            columns: $board->columns,
        ));
    } elseif ($mode === 'missing_column') {
        $fake->seedBoard(new MondayBoardMetadata(
            id: 'fake_board_100',
            name: 'Intake',
            groups: $board->groups,
            columns: array_values(array_filter(
                $board->columns,
                static fn (MondayColumnMetadata $column): bool => $column->id !== 'text_quote_number',
            )),
        ));
    } elseif ($mode === 'type_mismatch') {
        $fake->seedBoard(new MondayBoardMetadata(
            id: 'fake_board_100',
            name: 'Intake',
            groups: $board->groups,
            columns: [
                new MondayColumnMetadata('text_integration_key', 'Key', MondayColumnType::Text),
                new MondayColumnMetadata('text_quote_number', 'Quote', MondayColumnType::Numbers),
                new MondayColumnMetadata('text_company_name', 'Company', MondayColumnType::Text),
                new MondayColumnMetadata('date_accepted', 'Accepted', MondayColumnType::Date),
                new MondayColumnMetadata('numbers_grand_total', 'Total', MondayColumnType::Numbers),
                new MondayColumnMetadata('link_halftone', 'URL', MondayColumnType::Link),
                new MondayColumnMetadata('status_intake', 'Status', MondayColumnType::Status, ['New Intake']),
                new MondayColumnMetadata('text_organization', 'Org', MondayColumnType::Text),
            ],
        ));
    } elseif ($mode === 'wrong_integration_key_type') {
        $fake->seedBoard(new MondayBoardMetadata(
            id: 'fake_board_100',
            name: 'Intake',
            groups: $board->groups,
            columns: [
                new MondayColumnMetadata('text_integration_key', 'Key', MondayColumnType::Numbers),
                new MondayColumnMetadata('text_quote_number', 'Quote', MondayColumnType::Text),
                new MondayColumnMetadata('text_company_name', 'Company', MondayColumnType::Text),
                new MondayColumnMetadata('date_accepted', 'Accepted', MondayColumnType::Date),
                new MondayColumnMetadata('numbers_grand_total', 'Total', MondayColumnType::Numbers),
                new MondayColumnMetadata('link_halftone', 'URL', MondayColumnType::Link),
                new MondayColumnMetadata('status_intake', 'Status', MondayColumnType::Status, ['New Intake']),
                new MondayColumnMetadata('text_organization', 'Org', MondayColumnType::Text),
            ],
        ));
    } else {
        $payload['intake_status_label'] = 'Does Not Exist';
        $fake->seedBoard($board);
    }

    app()->instance(MondayApiClientInterface::class, $fake);
    Phase2E3CHelpers::bindTestCredentials();

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.store', $ctx['organization']), $payload)
        ->assertRedirect();

    $settings = OrganizationIntegrationSetting::query()->sole();

    if ($mode === 'wrong_integration_key_type') {
        $mapping = $settings->column_mapping_json;
        $mapping[MondayIntakeLogicalKey::IntegrationKey->value]['expected_type'] = MondayColumnType::Numbers->value;
        $settings->forceFill(['column_mapping_json' => $mapping])->save();
    }

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.validate', [$ctx['organization'], $settings]), [
            'expected_lock_version' => $settings->lock_version,
        ])
        ->assertRedirect();

    $settings->refresh();

    expect($settings->last_validation_status)->toBe(IntegrationValidationStatus::Invalid)
        ->and($settings->enabled)->toBeFalse()
        ->and($fake->recordedCreateRequests())->toHaveCount(0)
        ->and($fake->itemsByIntegrationKey())->toBe([])
        ->and(Http::recorded())->toHaveCount(0);
})->with([
    'missing_board',
    'missing_group',
    'missing_column',
    'type_mismatch',
    'wrong_integration_key_type',
    'invalid_status_label',
]);

test('phase 2e3b audits redact secrets and quote expiration stays absent', function () {
    $ctx = createTenantUser('owner');
    phase2e3bBindFakeClient();

    $this->actingAs($ctx['user'])
        ->get(route('org.integrations.settings.monday.show', $ctx['organization']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('explanation', fn ($lines) => collect($lines)->contains(
                fn ($line) => str_contains((string) $line, 'API tokens are never'),
            ))
            ->where('safety_notes', fn ($notes) => collect($notes)->contains(
                fn ($note) => str_contains((string) $note, 'Quote expiration'),
            ))
            ->missing('requested_due_date')
            ->etc());

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.store', $ctx['organization']), phase2e3bValidPayload())
        ->assertRedirect();

    $audit = AuditEvent::query()
        ->where('action', MondayOrganizationSettingsService::AUDIT_CREATED)
        ->sole();

    $encoded = json_encode($audit->after_json);

    expect($encoded)->not->toContain('api_token')
        ->and($encoded)->not->toContain('Bearer')
        ->and($encoded)->not->toContain('authorization')
        ->and($audit->after_json)->toHaveKey('board_id')
        ->and($audit->after_json)->toHaveKey('mappings')
        ->and($audit->after_json['mappings'])->not->toHaveKey('requested_due_date')
        ->and($audit->correlation_id)->not->toBeNull();
});

test('phase 2e3b monday consumer remains unregistered and no outbox delivery or receipt side effects', function () {
    Http::fake();
    $ctx = createTenantUser('owner');
    phase2e3bBindFakeClient();

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.store', $ctx['organization']), phase2e3bValidPayload())
        ->assertRedirect();

    $settings = OrganizationIntegrationSetting::query()->sole();

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.validate', [$ctx['organization'], $settings]), [
            'expected_lock_version' => $settings->lock_version,
        ])
        ->assertRedirect();

    $settings->refresh();

    $this->actingAs($ctx['user'])
        ->post(route('org.integrations.settings.monday.enable', [$ctx['organization'], $settings]), [
            'expected_lock_version' => $settings->lock_version,
        ])
        ->assertRedirect();

    $registry = app(IntegrationConsumerRegistry::class);

    expect($registry->handler(
        QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE,
        MondayConsumerKeys::CREATE_INTAKE_ITEM,
    ))->toBeNull()
        ->and(IntegrationOutbox::query()->count())->toBe(0)
        ->and(IntegrationOutboxDelivery::query()->count())->toBe(0)
        ->and(IntegrationProviderReceipt::query()->count())->toBe(0)
        ->and(IntegrationOutboxDeliveryStatus::cases())->not->toBeEmpty()
        ->and(Http::recorded())->toHaveCount(0)
        ->and(app()->bound(MondayApiClientInterface::class))->toBeTrue()
        ->and(app(MondayApiClientInterface::class))->toBeInstanceOf(FakeMondayApiClient::class);
});

test('phase 2e3b service requires tenant context', function () {
    $ctx = createTenantUser('owner');
    TenantContext::clear();

    expect(fn () => app(MondayOrganizationSettingsService::class)->create(
        organization: $ctx['organization'],
        input: [
            'board_id' => 'b',
            'group_id' => 'g',
            'api_version' => MondayApiVersion::PINNED,
            'item_name_template' => '{quote_number}',
            'line_detail_mode' => 'summary',
            'column_mapping_json' => OrganizationIntegrationSettingFactory::defaultColumnMapping(),
            'status_label_mappings_json' => ['intake_status' => 'New Intake'],
        ],
        actor: $ctx['user'],
    ))->toThrow(InvalidArgumentException::class);

    phase2e3bEstablishTenant($ctx);

    $created = app(MondayOrganizationSettingsService::class)->create(
        organization: $ctx['organization'],
        input: [
            'board_id' => 'b1',
            'group_id' => 'g1',
            'api_version' => MondayApiVersion::PINNED,
            'item_name_template' => '{quote_number} — {company_name}',
            'line_detail_mode' => 'none',
            'column_mapping_json' => OrganizationIntegrationSettingFactory::defaultColumnMapping(),
            'status_label_mappings_json' => ['intake_status' => 'New Intake'],
        ],
        actor: $ctx['user'],
    );

    expect($created->line_detail_mode->value)->toBe('none');
});
