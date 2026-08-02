<?php

namespace App\Support\Integrations\Monday;

use App\Enums\IntegrationLineDetailMode;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationValidationStatus;
use App\Enums\MondayColumnType;
use App\Enums\MondayIntakeLogicalKey;
use App\Models\Organization;
use App\Models\OrganizationIntegrationSetting;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Integrations\StaleIntegrationSettingsException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Transactional Monday organization settings mutations.
 *
 * Material changes always disable the integration, clear validation success, and
 * bump lock_version so enablement requires a fresh explicit validation.
 */
final class MondayOrganizationSettingsService
{
    public const AUDIT_CREATED = 'integrations.monday.settings_created';

    public const AUDIT_UPDATED = 'integrations.monday.settings_updated';

    public const AUDIT_VALIDATED = 'integrations.monday.settings_validated';

    public const AUDIT_ENABLED = 'integrations.monday.enabled';

    public const AUDIT_DISABLED = 'integrations.monday.disabled';

    public function __construct(
        private Auditor $auditor,
    ) {}

    /**
     * @param  array{
     *     board_id: string,
     *     group_id: string,
     *     api_version: string,
     *     item_name_template: string,
     *     line_detail_mode: string,
     *     column_mapping_json: array<string, array{column_id: string, expected_type: string, required: bool, enabled: bool}>,
     *     status_label_mappings_json: array{intake_status: string},
     *     expected_lock_version?: int|null
     * }  $input
     */
    public function create(Organization $organization, array $input, User $actor): OrganizationIntegrationSetting
    {
        $this->assertTenantOwnsOrganization($organization);
        $this->assertNoSecrets($input);

        return DB::transaction(function () use ($organization, $input, $actor): OrganizationIntegrationSetting {
            $exists = OrganizationIntegrationSetting::query()
                ->where('organization_id', $organization->id)
                ->where('provider', IntegrationProvider::Monday->value)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw new InvalidArgumentException('Monday settings already exist for this organization.');
            }

            $normalized = $this->normalizeConfigurationInput($input);

            $settings = new OrganizationIntegrationSetting;
            $settings->forceFill([
                'parent_account_id' => $organization->parent_account_id,
                'organization_id' => $organization->id,
                'provider' => IntegrationProvider::Monday,
                'enabled' => false,
                'api_version' => $normalized['api_version'],
                'board_id' => $normalized['board_id'],
                'group_id' => $normalized['group_id'],
                'item_name_template' => $normalized['item_name_template'],
                'line_detail_mode' => $normalized['line_detail_mode'],
                'column_mapping_json' => $normalized['column_mapping_json'],
                'status_label_mappings_json' => $normalized['status_label_mappings_json'],
                'last_validated_at' => null,
                'last_validation_status' => IntegrationValidationStatus::NeverValidated,
                'last_validation_error_code' => null,
                'last_validation_error_message' => null,
                'lock_version' => 1,
            ])->save();

            $this->audit(
                action: self::AUDIT_CREATED,
                settings: $settings->fresh(),
                actor: $actor,
                before: null,
                after: $this->auditSnapshot($settings->fresh()),
            );

            return $settings->fresh();
        });
    }

    /**
     * @param  array{
     *     board_id: string,
     *     group_id: string,
     *     api_version: string,
     *     item_name_template: string,
     *     line_detail_mode: string,
     *     column_mapping_json: array<string, array{column_id: string, expected_type: string, required: bool, enabled: bool}>,
     *     status_label_mappings_json: array{intake_status: string}
     * }  $input
     */
    public function update(
        OrganizationIntegrationSetting $settings,
        array $input,
        int $expectedLockVersion,
        User $actor,
    ): OrganizationIntegrationSetting {
        $this->assertTenantOwnsSettings($settings);
        $this->assertNoSecrets($input);

        return DB::transaction(function () use ($settings, $input, $expectedLockVersion, $actor): OrganizationIntegrationSetting {
            /** @var OrganizationIntegrationSetting $locked */
            $locked = OrganizationIntegrationSetting::query()
                ->whereKey($settings->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertLock($locked, $expectedLockVersion);

            $before = $this->auditSnapshot($locked);
            $normalized = $this->normalizeConfigurationInput($input);

            $locked->forceFill([
                'enabled' => false,
                'api_version' => $normalized['api_version'],
                'board_id' => $normalized['board_id'],
                'group_id' => $normalized['group_id'],
                'item_name_template' => $normalized['item_name_template'],
                'line_detail_mode' => $normalized['line_detail_mode'],
                'column_mapping_json' => $normalized['column_mapping_json'],
                'status_label_mappings_json' => $normalized['status_label_mappings_json'],
                'last_validated_at' => null,
                'last_validation_status' => IntegrationValidationStatus::NeverValidated,
                'last_validation_error_code' => null,
                'last_validation_error_message' => null,
                'lock_version' => $locked->lock_version + 1,
            ])->save();

            $fresh = $locked->fresh();

            $this->audit(
                action: self::AUDIT_UPDATED,
                settings: $fresh,
                actor: $actor,
                before: $before,
                after: $this->auditSnapshot($fresh),
            );

            return $fresh;
        });
    }

    public function enable(OrganizationIntegrationSetting $settings, int $expectedLockVersion, User $actor): OrganizationIntegrationSetting
    {
        $this->assertTenantOwnsSettings($settings);

        return DB::transaction(function () use ($settings, $expectedLockVersion, $actor): OrganizationIntegrationSetting {
            /** @var OrganizationIntegrationSetting $locked */
            $locked = OrganizationIntegrationSetting::query()
                ->whereKey($settings->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertLock($locked, $expectedLockVersion);
            $this->assertCanEnable($locked);

            if ($locked->enabled) {
                return $locked;
            }

            $before = $this->auditSnapshot($locked);

            $locked->forceFill([
                'enabled' => true,
                'lock_version' => $locked->lock_version + 1,
            ])->save();

            $fresh = $locked->fresh();

            $this->audit(
                action: self::AUDIT_ENABLED,
                settings: $fresh,
                actor: $actor,
                before: $before,
                after: $this->auditSnapshot($fresh),
            );

            return $fresh;
        });
    }

    public function disable(OrganizationIntegrationSetting $settings, int $expectedLockVersion, User $actor): OrganizationIntegrationSetting
    {
        $this->assertTenantOwnsSettings($settings);

        return DB::transaction(function () use ($settings, $expectedLockVersion, $actor): OrganizationIntegrationSetting {
            /** @var OrganizationIntegrationSetting $locked */
            $locked = OrganizationIntegrationSetting::query()
                ->whereKey($settings->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertLock($locked, $expectedLockVersion);

            if (! $locked->enabled) {
                return $locked;
            }

            $before = $this->auditSnapshot($locked);

            $locked->forceFill([
                'enabled' => false,
                'lock_version' => $locked->lock_version + 1,
            ])->save();

            $fresh = $locked->fresh();

            $this->audit(
                action: self::AUDIT_DISABLED,
                settings: $fresh,
                actor: $actor,
                before: $before,
                after: $this->auditSnapshot($fresh),
            );

            return $fresh;
        });
    }

    /**
     * Persist a validation outcome without accepting lifecycle fields from user input.
     */
    public function recordValidationOutcome(
        OrganizationIntegrationSetting $settings,
        int $expectedLockVersion,
        IntegrationValidationStatus $status,
        ?string $errorCode,
        ?string $errorMessage,
        User $actor,
    ): OrganizationIntegrationSetting {
        $this->assertTenantOwnsSettings($settings);

        return DB::transaction(function () use ($settings, $expectedLockVersion, $status, $errorCode, $errorMessage, $actor): OrganizationIntegrationSetting {
            /** @var OrganizationIntegrationSetting $locked */
            $locked = OrganizationIntegrationSetting::query()
                ->whereKey($settings->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertLock($locked, $expectedLockVersion);

            $before = $this->auditSnapshot($locked);

            $locked->forceFill([
                'last_validated_at' => now(),
                'last_validation_status' => $status,
                'last_validation_error_code' => $errorCode,
                'last_validation_error_message' => $errorMessage,
                'lock_version' => $locked->lock_version + 1,
            ])->save();

            $fresh = $locked->fresh();

            $this->audit(
                action: self::AUDIT_VALIDATED,
                settings: $fresh,
                actor: $actor,
                before: $before,
                after: $this->auditSnapshot($fresh),
            );

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     board_id: string,
     *     group_id: string,
     *     api_version: string,
     *     item_name_template: string,
     *     line_detail_mode: IntegrationLineDetailMode,
     *     column_mapping_json: array<string, array{column_id: string, expected_type: string, required: bool, enabled: bool}>,
     *     status_label_mappings_json: array{intake_status: string}
     * }
     */
    private function normalizeConfigurationInput(array $input): array
    {
        $boardId = trim((string) $input['board_id']);
        $groupId = trim((string) $input['group_id']);
        $apiVersion = trim((string) $input['api_version']);
        $template = MondayItemNameTemplate::assertValid((string) $input['item_name_template']);
        $lineDetail = IntegrationLineDetailMode::tryFrom((string) $input['line_detail_mode']);

        if ($boardId === '' || strlen($boardId) > 64) {
            throw new InvalidArgumentException('Board ID must be a non-blank string up to 64 characters.');
        }

        if ($groupId === '' || strlen($groupId) > 64) {
            throw new InvalidArgumentException('Group ID must be a non-blank string up to 64 characters.');
        }

        if ($apiVersion !== MondayApiVersion::PINNED) {
            throw new InvalidArgumentException('Only the pinned Monday API version '.MondayApiVersion::PINNED.' is approved.');
        }

        if ($lineDetail === null) {
            throw new InvalidArgumentException('Line detail mode must be summary or none.');
        }

        /** @var array<string, array{column_id: string, expected_type: string, required: bool, enabled: bool}> $mapping */
        $mapping = $input['column_mapping_json'];
        $mappingSet = MondayColumnMappingSet::fromArray($mapping);
        $statusLabels = $input['status_label_mappings_json'];
        $structural = $mappingSet->validateForActivation($statusLabels);

        if (! $structural->valid) {
            throw new InvalidArgumentException(implode(' ', $structural->errors));
        }

        return [
            'board_id' => $boardId,
            'group_id' => $groupId,
            'api_version' => $apiVersion,
            'item_name_template' => $template,
            'line_detail_mode' => $lineDetail,
            'column_mapping_json' => $mappingSet->toArray(),
            'status_label_mappings_json' => [
                'intake_status' => trim((string) $statusLabels['intake_status']),
            ],
        ];
    }

    private function assertCanEnable(OrganizationIntegrationSetting $settings): void
    {
        if ($settings->last_validation_status !== IntegrationValidationStatus::Valid) {
            throw new InvalidArgumentException('Monday settings must be successfully validated before enabling.');
        }

        if ($settings->last_validated_at === null) {
            throw new InvalidArgumentException('Monday settings validation timestamp is missing.');
        }

        if ($settings->last_validation_error_code !== null || $settings->last_validation_error_message !== null) {
            throw new InvalidArgumentException('Monday settings still have a validation error.');
        }

        $boardId = trim((string) $settings->board_id);
        $groupId = trim((string) $settings->group_id);

        if ($boardId === '' || $groupId === '') {
            throw new InvalidArgumentException('Board and group IDs are required before enabling.');
        }

        if ($settings->api_version !== MondayApiVersion::PINNED) {
            throw new InvalidArgumentException('Pinned Monday API version is required before enabling.');
        }

        $mappingSet = MondayColumnMappingSet::fromArray($settings->column_mapping_json);
        $structural = $mappingSet->validateForActivation($settings->status_label_mappings_json);

        if (! $structural->valid) {
            throw new InvalidArgumentException('Required Monday mappings are incomplete.');
        }
    }

    private function assertLock(OrganizationIntegrationSetting $settings, int $expectedLockVersion): void
    {
        if ($settings->lock_version !== $expectedLockVersion) {
            throw new StaleIntegrationSettingsException;
        }
    }

    private function assertTenantOwnsOrganization(Organization $organization): void
    {
        if (! TenantContext::has()) {
            throw new InvalidArgumentException('Tenant context is required.');
        }

        $tenant = TenantContext::get();

        if ($tenant->organizationId !== $organization->id || $tenant->parentAccountId !== $organization->parent_account_id) {
            throw new InvalidArgumentException('Organization does not match the current tenant.');
        }
    }

    private function assertTenantOwnsSettings(OrganizationIntegrationSetting $settings): void
    {
        if (! TenantContext::has()) {
            throw new InvalidArgumentException('Tenant context is required.');
        }

        $tenant = TenantContext::get();

        if (
            (int) $settings->organization_id !== $tenant->organizationId
            || (int) $settings->parent_account_id !== $tenant->parentAccountId
        ) {
            throw new InvalidArgumentException('Settings do not match the current tenant Monday configuration.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assertNoSecrets(array $input): void
    {
        MondaySensitivePayloadGuard::assertNoSensitiveKeys($input);

        foreach ([
            'parent_account_id',
            'organization_id',
            'provider',
            'enabled',
            'last_validated_at',
            'last_validation_status',
            'last_validation_error_code',
            'last_validation_error_message',
            'lock_version',
        ] as $forbidden) {
            if (array_key_exists($forbidden, $input)) {
                throw new InvalidArgumentException("Field [{$forbidden}] cannot be submitted.");
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(OrganizationIntegrationSetting $settings): array
    {
        $mapping = $settings->column_mapping_json ?? [];
        $mappingSummary = [];

        foreach ($mapping as $logicalKey => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $mappingSummary[(string) $logicalKey] = [
                'column_id' => $entry['column_id'] ?? null,
                'expected_type' => $entry['expected_type'] ?? null,
                'required' => (bool) ($entry['required'] ?? false),
                'enabled' => (bool) ($entry['enabled'] ?? false),
            ];
        }

        return [
            'organization_id' => $settings->organization_id,
            'provider' => $settings->provider->value,
            'enabled' => $settings->enabled,
            'api_version' => $settings->api_version,
            'board_id' => $settings->board_id,
            'group_id' => $settings->group_id,
            'item_name_template' => $settings->item_name_template,
            'line_detail_mode' => $settings->line_detail_mode->value,
            'mappings' => $mappingSummary,
            'status_label_mappings' => $settings->status_label_mappings_json,
            'last_validation_status' => $settings->last_validation_status?->value,
            'last_validation_error_code' => $settings->last_validation_error_code,
            'lock_version' => $settings->lock_version,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(
        string $action,
        OrganizationIntegrationSetting $settings,
        User $actor,
        ?array $before,
        ?array $after,
    ): void {
        $this->auditor->append(
            parentAccount: $settings->parentAccount,
            action: $action,
            subjectType: OrganizationIntegrationSetting::class,
            subjectId: $settings->id,
            organization: $settings->organization,
            actor: $actor,
            before: $before,
            after: $after,
            correlationId: (string) Str::uuid(),
        );
    }

    /**
     * Build mapping JSON from explicit form fields (no raw JSON).
     *
     * @param  array<string, array{column_id: string, expected_type: string}>  $required
     * @param  array<string, array{enabled?: bool, column_id?: string|null, expected_type?: string|null}>  $optional
     * @return array<string, array{column_id: string, expected_type: string, required: bool, enabled: bool}>
     */
    public static function buildMappingFromFormFields(array $required, array $optional): array
    {
        $mapping = [];
        $requiredKeys = array_map(
            static fn (MondayIntakeLogicalKey $key): string => $key->value,
            MondayIntakeLogicalKey::requiredForActivation(),
        );
        $optionalKeysAllowed = [
            MondayIntakeLogicalKey::Organization->value,
            MondayIntakeLogicalKey::RevisionNumber->value,
            MondayIntakeLogicalKey::PrimaryContact->value,
            MondayIntakeLogicalKey::Salesperson->value,
            MondayIntakeLogicalKey::PretaxTotal->value,
            MondayIntakeLogicalKey::TaxTotal->value,
            MondayIntakeLogicalKey::LineSummary->value,
        ];

        foreach (array_keys($required) as $logicalKey) {
            if (! in_array($logicalKey, $requiredKeys, true)) {
                throw new InvalidArgumentException("Unknown required mapping [{$logicalKey}].");
            }
        }

        foreach (array_keys($optional) as $logicalKey) {
            if (! in_array($logicalKey, $optionalKeysAllowed, true)) {
                throw new InvalidArgumentException("Unknown optional mapping [{$logicalKey}].");
            }
        }

        foreach (MondayIntakeLogicalKey::requiredForActivation() as $key) {
            if (! isset($required[$key->value])) {
                throw new InvalidArgumentException("Required mapping [{$key->value}] is missing.");
            }

            $type = MondayColumnType::tryFrom((string) $required[$key->value]['expected_type']);

            if ($type === null) {
                throw new InvalidArgumentException("Required mapping [{$key->value}] has an unsupported type.");
            }

            $mapping[$key->value] = [
                'column_id' => trim((string) $required[$key->value]['column_id']),
                'expected_type' => $type->value,
                'required' => true,
                'enabled' => true,
            ];
        }

        $optionalKeys = [
            MondayIntakeLogicalKey::Organization,
            MondayIntakeLogicalKey::RevisionNumber,
            MondayIntakeLogicalKey::PrimaryContact,
            MondayIntakeLogicalKey::Salesperson,
            MondayIntakeLogicalKey::PretaxTotal,
            MondayIntakeLogicalKey::TaxTotal,
            MondayIntakeLogicalKey::LineSummary,
        ];

        foreach ($optionalKeys as $key) {
            $row = $optional[$key->value] ?? null;

            if (! is_array($row) || ! ($row['enabled'] ?? false)) {
                continue;
            }

            $columnId = trim((string) ($row['column_id'] ?? ''));
            $type = MondayColumnType::tryFrom((string) ($row['expected_type'] ?? ''));

            if ($columnId === '' || $type === null) {
                throw new InvalidArgumentException("Optional mapping [{$key->value}] is enabled but incomplete.");
            }

            $mapping[$key->value] = [
                'column_id' => $columnId,
                'expected_type' => $type->value,
                'required' => false,
                'enabled' => true,
            ];
        }

        return $mapping;
    }
}
