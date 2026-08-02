<?php

namespace App\Support\Integrations\Monday;

use App\Enums\IntegrationValidationStatus;
use App\Enums\MondayColumnType;
use App\Enums\MondayIntakeLogicalKey;
use App\Models\OrganizationIntegrationSetting;
use App\Models\User;
use App\Support\Integrations\Monday\Dto\MondayBoardMetadata;
use App\Support\Integrations\Monday\Dto\MondayColumnMetadata;
use App\Support\Integrations\Outbox\IntegrationErrorSanitizer;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Throwable;

/**
 * Provider-neutral Monday configuration validation against live board metadata
 * via MondayApiClientInterface. Creates no remote items.
 */
final class MondayConfigurationValidator
{
    public function __construct(
        private Application $app,
        private MondayOrganizationSettingsService $settings,
        private IntegrationErrorSanitizer $sanitizer,
    ) {}

    public function validate(
        OrganizationIntegrationSetting $settings,
        int $expectedLockVersion,
        User $actor,
    ): OrganizationIntegrationSetting {
        try {
            $structural = MondayColumnMappingSet::fromArray($settings->column_mapping_json)
                ->validateForActivation($settings->status_label_mappings_json);
        } catch (InvalidArgumentException $exception) {
            return $this->settings->recordValidationOutcome(
                settings: $settings,
                expectedLockVersion: $expectedLockVersion,
                status: IntegrationValidationStatus::Invalid,
                errorCode: $this->sanitizer->code('invalid_local_configuration'),
                errorMessage: $this->sanitizer->message($exception->getMessage()),
                actor: $actor,
            );
        }

        if (! $structural->valid) {
            return $this->settings->recordValidationOutcome(
                settings: $settings,
                expectedLockVersion: $expectedLockVersion,
                status: IntegrationValidationStatus::Invalid,
                errorCode: $this->sanitizer->code('invalid_local_configuration'),
                errorMessage: $this->sanitizer->message(implode(' ', $structural->errors)),
                actor: $actor,
            );
        }

        $boardId = trim((string) $settings->board_id);
        $groupId = trim((string) $settings->group_id);

        if ($boardId === '' || $groupId === '') {
            return $this->settings->recordValidationOutcome(
                settings: $settings,
                expectedLockVersion: $expectedLockVersion,
                status: IntegrationValidationStatus::Invalid,
                errorCode: $this->sanitizer->code('missing_board_or_group'),
                errorMessage: $this->sanitizer->message('Board ID and group ID are required before validation.'),
                actor: $actor,
            );
        }

        if ($settings->api_version !== MondayApiVersion::PINNED) {
            return $this->settings->recordValidationOutcome(
                settings: $settings,
                expectedLockVersion: $expectedLockVersion,
                status: IntegrationValidationStatus::Invalid,
                errorCode: $this->sanitizer->code('unsupported_api_version'),
                errorMessage: $this->sanitizer->message('Only the pinned Monday API version is approved.'),
                actor: $actor,
            );
        }

        if (! $this->app->bound(MondayApiClientInterface::class)) {
            return $this->settings->recordValidationOutcome(
                settings: $settings,
                expectedLockVersion: $expectedLockVersion,
                status: IntegrationValidationStatus::ClientNotConfigured,
                errorCode: $this->sanitizer->code('client_not_configured'),
                errorMessage: $this->sanitizer->message(
                    'Monday API client is not configured. Configuration was saved but cannot be validated until the Monday client is connected.',
                ),
                actor: $actor,
            );
        }

        /** @var MondayApiClientInterface $client */
        $client = $this->app->make(MondayApiClientInterface::class);

        try {
            $board = $client->inspectBoard($boardId);
        } catch (MondayApiClientException $exception) {
            return $this->settings->recordValidationOutcome(
                settings: $settings,
                expectedLockVersion: $expectedLockVersion,
                status: IntegrationValidationStatus::Invalid,
                errorCode: $this->sanitizer->code($exception->error->code),
                errorMessage: $this->sanitizer->message($exception->error->message),
                actor: $actor,
            );
        } catch (Throwable $exception) {
            return $this->settings->recordValidationOutcome(
                settings: $settings,
                expectedLockVersion: $expectedLockVersion,
                status: IntegrationValidationStatus::Invalid,
                errorCode: $this->sanitizer->code('board_inspection_failed'),
                errorMessage: $this->sanitizer->message('Unable to inspect the Monday board.'),
                actor: $actor,
            );
        }

        $error = $this->inspectBoardAgainstSettings($board, $settings, $groupId);

        if ($error !== null) {
            return $this->settings->recordValidationOutcome(
                settings: $settings,
                expectedLockVersion: $expectedLockVersion,
                status: IntegrationValidationStatus::Invalid,
                errorCode: $this->sanitizer->code($error['code']),
                errorMessage: $this->sanitizer->message($error['message']),
                actor: $actor,
            );
        }

        return $this->settings->recordValidationOutcome(
            settings: $settings,
            expectedLockVersion: $expectedLockVersion,
            status: IntegrationValidationStatus::Valid,
            errorCode: null,
            errorMessage: null,
            actor: $actor,
        );
    }

    /**
     * @return array{code: string, message: string}|null
     */
    private function inspectBoardAgainstSettings(
        MondayBoardMetadata $board,
        OrganizationIntegrationSetting $settings,
        string $groupId,
    ): ?array {
        $groupIds = array_map(static fn ($group): string => $group->id, $board->groups);

        if (! in_array($groupId, $groupIds, true)) {
            return [
                'code' => 'missing_group',
                'message' => 'The configured Monday group was not found on the board.',
            ];
        }

        /** @var array<string, MondayColumnMetadata> $columnsById */
        $columnsById = [];

        foreach ($board->columns as $column) {
            $columnsById[$column->id] = $column;
        }

        $mappingSet = MondayColumnMappingSet::fromArray($settings->column_mapping_json);

        foreach ($mappingSet->entries as $entry) {
            if (! $entry->enabled) {
                continue;
            }

            $column = $columnsById[$entry->columnId] ?? null;

            if ($column === null) {
                return [
                    'code' => 'missing_column',
                    'message' => "Mapped Monday column [{$entry->columnId}] was not found on the board.",
                ];
            }

            if ($column->type !== $entry->expectedType) {
                return [
                    'code' => 'column_type_mismatch',
                    'message' => "Mapped Monday column [{$entry->columnId}] type [{$column->type->value}] does not match expected [{$entry->expectedType->value}].",
                ];
            }

            if ($entry->logicalKey === MondayIntakeLogicalKey::IntegrationKey
                && $column->type !== MondayColumnType::Text) {
                return [
                    'code' => 'integration_key_type_invalid',
                    'message' => 'The integration key column must be a Monday text column.',
                ];
            }

            if ($entry->logicalKey === MondayIntakeLogicalKey::HalftoneUrl
                && ! in_array($column->type, [MondayColumnType::Link, MondayColumnType::Text], true)) {
                return [
                    'code' => 'halftone_url_type_invalid',
                    'message' => 'The Halftone Brain URL column must be a Monday link or text column.',
                ];
            }

            if ($entry->logicalKey === MondayIntakeLogicalKey::IntakeStatus) {
                $configuredLabel = trim((string) (($settings->status_label_mappings_json ?? [])['intake_status'] ?? ''));

                if ($configuredLabel === '') {
                    return [
                        'code' => 'missing_status_label',
                        'message' => 'An intake status label is required.',
                    ];
                }

                if ($column->statusLabels !== [] && ! in_array($configuredLabel, $column->statusLabels, true)) {
                    return [
                        'code' => 'invalid_status_label',
                        'message' => 'The configured intake status label was not found on the Monday status column.',
                    ];
                }
            }
        }

        return null;
    }
}
