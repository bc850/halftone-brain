<?php

namespace Tests\Support;

use App\Enums\IntegrationLineDetailMode;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationValidationStatus;
use App\Enums\MondayColumnType;
use App\Enums\MondayIntakeLogicalKey;
use App\Enums\QuoteRevisionStatus;
use App\Enums\QuoteStatusTransitionSource;
use App\Models\Deal;
use App\Models\IntegrationOutbox;
use App\Models\IntegrationOutboxDelivery;
use App\Models\OrganizationIntegrationSetting;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionPartySnapshot;
use App\Support\Integrations\Monday\AcceptedQuoteMondayMapper;
use App\Support\Integrations\Monday\Credentials\MondayCredentials;
use App\Support\Integrations\Monday\Credentials\MondayCredentialsProviderInterface;
use App\Support\Integrations\Monday\FakeMondayApiClient;
use App\Support\Integrations\Monday\HttpMondayApiClient;
use App\Support\Integrations\Monday\MondayApiClientInterface;
use App\Support\Integrations\Monday\MondayConsumerKeys;
use App\Support\Integrations\Monday\MondayErrorClassifier;
use App\Support\Integrations\Outbox\IntegrationErrorSanitizer;
use App\Support\Quotes\QuoteRevisionTransitionService;
use App\Support\Tenancy\PermissionResolver;
use App\Support\Tenancy\TenantContext;
use Database\Factories\OrganizationIntegrationSettingFactory;
use Database\Factories\QuoteRevisionPartySnapshotFactory;

final class Phase2E3CHelpers
{
    public static function bindTestCredentials(string $token = 'test-monday-personal-token'): void
    {
        app()->instance(MondayCredentialsProviderInterface::class, new class($token) implements MondayCredentialsProviderInterface
        {
            public function __construct(private string $token) {}

            public function get(): ?MondayCredentials
            {
                return new MondayCredentials($this->token);
            }
        });
    }

    public static function clearCredentials(): void
    {
        app()->instance(MondayCredentialsProviderInterface::class, new class implements MondayCredentialsProviderInterface
        {
            public function get(): ?MondayCredentials
            {
                return null;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public static function establishTenant(array $ctx): void
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

    public static function buildHttpClient(int $maxResponseBytes = 1_048_576): HttpMondayApiClient
    {
        return new HttpMondayApiClient(
            credentials: new MondayCredentials('test-monday-personal-token'),
            classifier: app(MondayErrorClassifier::class),
            sanitizer: app(IntegrationErrorSanitizer::class),
            apiUrl: 'https://api.monday.com/v2',
            apiVersion: '2026-07',
            connectTimeoutSeconds: 5,
            timeoutSeconds: 20,
            maxResponseBytes: $maxResponseBytes,
        );
    }

    public static function bindFakeClient(?FakeMondayApiClient $client = null): FakeMondayApiClient
    {
        $fake = $client ?? new FakeMondayApiClient;
        $fake->seedDefaultBoard();
        app()->instance(MondayApiClientInterface::class, $fake);

        return $fake;
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public static function preparePartySnapshot(array $ctx, QuoteRevision $revision, bool $withOptionalPartyFields = true): QuoteRevision
    {
        if ($revision->partySnapshot === null) {
            QuoteRevisionPartySnapshotFactory::createForRevision($revision, $ctx['membership']);
            $revision->load('partySnapshot');
        }

        if ($revision->partySnapshot !== null) {
            $party = $revision->partySnapshot;
            $party->forceFill([
                'contact_name' => $withOptionalPartyFields ? 'Dana Buyer' : null,
                'salesperson_name' => $withOptionalPartyFields ? 'Sam Seller' : null,
            ])->saveQuietly();
            $revision->load('partySnapshot');
        }

        return $revision->fresh(['partySnapshot']) ?? $revision;
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public static function acceptRevision(array $ctx, Quote $quote, QuoteRevision $revision, bool $withOptionalPartyFields = true): QuoteRevision
    {
        $revision = self::preparePartySnapshot($ctx, $revision, $withOptionalPartyFields);

        $revision->forceFill([
            'subtotal_cents' => 10050,
            'discount_cents' => 50,
            'tax_cents' => 800,
            'grand_total_cents' => 10800,
            'expiration_date' => '2099-12-31',
        ])->save();

        foreach ([QuoteRevisionStatus::Approved, QuoteRevisionStatus::Sent, QuoteRevisionStatus::Accepted] as $status) {
            $quote = $quote->fresh() ?? $quote;
            $revision = $revision->fresh() ?? $revision;

            $revision = app(QuoteRevisionTransitionService::class)->transition(
                quote: $quote,
                revision: $revision,
                to: $status,
                source: QuoteStatusTransitionSource::User,
                expectedQuoteLockVersion: $quote->lock_version,
                expectedRevisionLockVersion: $revision->lock_version,
                actor: $ctx['user'],
                actorMembership: $ctx['membership'],
            );
        }

        return $revision->fresh(['partySnapshot', 'lineItems', 'currentTaxCalculation']) ?? $revision;
    }

    /**
     * @return array<string, array{column_id: string, expected_type: string, required: bool, enabled: bool}>
     */
    public static function fullColumnMapping(): array
    {
        $mapping = OrganizationIntegrationSettingFactory::defaultColumnMapping();

        $mapping[MondayIntakeLogicalKey::Organization->value] = [
            'column_id' => 'text_organization',
            'expected_type' => MondayColumnType::Text->value,
            'required' => false,
            'enabled' => true,
        ];
        $mapping[MondayIntakeLogicalKey::RevisionNumber->value] = [
            'column_id' => 'text_revision_number',
            'expected_type' => MondayColumnType::Text->value,
            'required' => false,
            'enabled' => true,
        ];
        $mapping[MondayIntakeLogicalKey::PrimaryContact->value] = [
            'column_id' => 'text_primary_contact',
            'expected_type' => MondayColumnType::Text->value,
            'required' => false,
            'enabled' => true,
        ];
        $mapping[MondayIntakeLogicalKey::Salesperson->value] = [
            'column_id' => 'text_salesperson',
            'expected_type' => MondayColumnType::Text->value,
            'required' => false,
            'enabled' => true,
        ];
        $mapping[MondayIntakeLogicalKey::PretaxTotal->value] = [
            'column_id' => 'numbers_pretax_total',
            'expected_type' => MondayColumnType::Numbers->value,
            'required' => false,
            'enabled' => true,
        ];
        $mapping[MondayIntakeLogicalKey::TaxTotal->value] = [
            'column_id' => 'numbers_tax_total',
            'expected_type' => MondayColumnType::Numbers->value,
            'required' => false,
            'enabled' => true,
        ];
        $mapping[MondayIntakeLogicalKey::LineSummary->value] = [
            'column_id' => 'long_text_line_summary',
            'expected_type' => MondayColumnType::LongText->value,
            'required' => false,
            'enabled' => true,
        ];

        return $mapping;
    }

    /**
     * @return array{
     *     ctx: array<string, mixed>,
     *     quote: Quote,
     *     revision: QuoteRevision,
     *     settings: OrganizationIntegrationSetting,
     *     party: QuoteRevisionPartySnapshot|null
     * }
     */
    public static function acceptedQuoteFixture(array $settingsOverrides = [], bool $withOptionalPartyFields = true): array
    {
        $fixture = Phase2C2Fixture::draftQuote();
        $ctx = $fixture['ctx'];
        $quote = $fixture['quote'];
        $revision = $fixture['revision'];

        self::establishTenant($ctx);

        $revision = self::acceptRevision($ctx, $quote, $revision, $withOptionalPartyFields);

        $settings = OrganizationIntegrationSetting::factory()->create(array_merge([
            'organization_id' => $ctx['organization']->id,
            'parent_account_id' => $ctx['parent']->id,
            'provider' => IntegrationProvider::Monday,
            'board_id' => 'fake_board_100',
            'group_id' => 'fake_group_100',
            'enabled' => true,
            'last_validation_status' => IntegrationValidationStatus::Valid,
            'last_validated_at' => now(),
            'last_validation_error_code' => null,
            'api_version' => '2026-07',
            'item_name_template' => '{quote_number} — {company_name}',
            'line_detail_mode' => IntegrationLineDetailMode::Summary,
            'column_mapping_json' => self::fullColumnMapping(),
        ], $settingsOverrides));

        return [
            'ctx' => $ctx,
            'quote' => $quote->fresh() ?? $quote,
            'revision' => $revision->fresh() ?? $revision,
            'settings' => $settings,
            'party' => $revision->partySnapshot,
        ];
    }

    /**
     * @return array{
     *     ctx: array<string, mixed>,
     *     quote: Quote,
     *     revision: QuoteRevision,
     *     settings: OrganizationIntegrationSetting,
     *     outbox: IntegrationOutbox,
     *     delivery: IntegrationOutboxDelivery,
     *     integrationKey: string,
     *     fake: FakeMondayApiClient
     * }
     */
    public static function reconciliationFixture(): array
    {
        $fixture = Phase2C2Fixture::draftQuote();
        $ctx = $fixture['ctx'];
        $quote = $fixture['quote'];
        $revision = $fixture['revision'];

        self::establishTenant($ctx);

        $revision = self::acceptRevision($ctx, $quote, $revision);

        $settings = OrganizationIntegrationSetting::factory()->create([
            'organization_id' => $ctx['organization']->id,
            'parent_account_id' => $ctx['parent']->id,
            'board_id' => 'fake_board_100',
            'group_id' => 'fake_group_100',
            'enabled' => true,
            'last_validation_status' => IntegrationValidationStatus::Valid,
            'last_validated_at' => now(),
            'last_validation_error_code' => null,
            'api_version' => '2026-07',
        ]);

        $outbox = IntegrationOutbox::factory()->create([
            'organization_id' => $ctx['organization']->id,
            'parent_account_id' => $ctx['parent']->id,
            'aggregate_id' => $revision->id,
            'payload_json' => [
                'quote_id' => $quote->id,
                'quote_revision_id' => $revision->id,
                'organization_id' => $ctx['organization']->id,
                'document_id' => 1,
                'document_version' => 1,
            ],
        ]);

        $delivery = IntegrationOutboxDelivery::factory()->create([
            'integration_outbox_id' => $outbox->id,
            'consumer_key' => MondayConsumerKeys::CREATE_INTAKE_ITEM,
            'organization_id' => $ctx['organization']->id,
            'parent_account_id' => $ctx['parent']->id,
        ]);

        $mapper = app(AcceptedQuoteMondayMapper::class);
        $integrationKey = $mapper->integrationKey(
            $ctx['organization']->id,
            $quote->id,
            (int) $revision->revision_number,
        );

        $fake = self::bindFakeClient();

        return compact('ctx', 'quote', 'revision', 'settings', 'outbox', 'delivery', 'integrationKey', 'fake');
    }

    /**
     * @return array{
     *     ctx: array<string, mixed>,
     *     quote: Quote,
     *     revision: QuoteRevision,
     *     deal: Deal,
     *     settings: OrganizationIntegrationSetting|null,
     *     outbox: IntegrationOutbox,
     *     delivery: IntegrationOutboxDelivery,
     *     fake: FakeMondayApiClient,
     *     integrationKey: string
     * }
     */
    public static function consumerFixture(array $settingsOverrides = [], bool $createSettings = true): array
    {
        $fixture = Phase2C2Fixture::draftQuote();
        $ctx = $fixture['ctx'];
        $quote = $fixture['quote'];
        $revision = $fixture['revision'];
        $deal = $fixture['deal'];

        self::establishTenant($ctx);
        self::bindTestCredentials();

        $revision = self::acceptRevision($ctx, $quote, $revision);

        $settings = null;

        if ($createSettings) {
            $settings = OrganizationIntegrationSetting::factory()->create(array_merge([
                'organization_id' => $ctx['organization']->id,
                'parent_account_id' => $ctx['parent']->id,
                'board_id' => 'fake_board_100',
                'group_id' => 'fake_group_100',
                'enabled' => true,
                'last_validation_status' => IntegrationValidationStatus::Valid,
                'last_validated_at' => now(),
                'last_validation_error_code' => null,
                'api_version' => '2026-07',
            ], $settingsOverrides));
        }

        $outbox = IntegrationOutbox::factory()->create([
            'organization_id' => $ctx['organization']->id,
            'parent_account_id' => $ctx['parent']->id,
            'aggregate_id' => $revision->id,
            'payload_json' => [
                'quote_id' => $quote->id,
                'quote_revision_id' => $revision->id,
                'organization_id' => $ctx['organization']->id,
                'document_id' => 1,
                'document_version' => 1,
            ],
        ]);

        $delivery = IntegrationOutboxDelivery::factory()->create([
            'integration_outbox_id' => $outbox->id,
            'consumer_key' => MondayConsumerKeys::CREATE_INTAKE_ITEM,
            'organization_id' => $ctx['organization']->id,
            'parent_account_id' => $ctx['parent']->id,
        ]);

        $fake = self::bindFakeClient();

        $mapper = app(AcceptedQuoteMondayMapper::class);
        $integrationKey = $mapper->integrationKey(
            $ctx['organization']->id,
            $quote->id,
            (int) $revision->fresh()->revision_number,
        );

        return compact('ctx', 'quote', 'revision', 'deal', 'settings', 'outbox', 'delivery', 'fake', 'integrationKey');
    }
}
