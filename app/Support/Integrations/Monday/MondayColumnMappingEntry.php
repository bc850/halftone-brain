<?php

namespace App\Support\Integrations\Monday;

use App\Enums\MondayColumnType;
use App\Enums\MondayIntakeLogicalKey;
use InvalidArgumentException;

/**
 * Immutable mapping entry for one logical intake field → Monday column.
 */
final readonly class MondayColumnMappingEntry
{
    public const MAX_COLUMN_ID_LENGTH = 64;

    public function __construct(
        public MondayIntakeLogicalKey $logicalKey,
        public string $columnId,
        public MondayColumnType $expectedType,
        public bool $required,
        public bool $enabled,
    ) {
        $trimmed = trim($columnId);

        if ($trimmed === '' || strlen($trimmed) > self::MAX_COLUMN_ID_LENGTH) {
            throw new InvalidArgumentException(
                'Monday column ID must be a non-blank string up to '.self::MAX_COLUMN_ID_LENGTH.' characters.',
            );
        }

        if ($logicalKey === MondayIntakeLogicalKey::IntegrationKey && $expectedType !== MondayColumnType::Text) {
            throw new InvalidArgumentException('integration_key mapping must use expected_type text.');
        }

        if ($logicalKey === MondayIntakeLogicalKey::HalftoneUrl
            && ! in_array($expectedType, [MondayColumnType::Link, MondayColumnType::Text], true)) {
            throw new InvalidArgumentException('halftone_url mapping must use link or text.');
        }

        if ($logicalKey === MondayIntakeLogicalKey::IntakeStatus && $expectedType !== MondayColumnType::Status) {
            throw new InvalidArgumentException('intake_status mapping must use expected_type status.');
        }
    }

    /**
     * @param  array{column_id?: mixed, expected_type?: mixed, required?: mixed, enabled?: mixed}  $raw
     */
    public static function fromArray(MondayIntakeLogicalKey $logicalKey, array $raw): self
    {
        if (! isset($raw['column_id'], $raw['expected_type'])) {
            throw new InvalidArgumentException("Mapping [{$logicalKey->value}] requires column_id and expected_type.");
        }

        if (! is_string($raw['column_id']) || ! is_string($raw['expected_type'])) {
            throw new InvalidArgumentException("Mapping [{$logicalKey->value}] column_id and expected_type must be strings.");
        }

        $type = MondayColumnType::tryFrom($raw['expected_type']);

        if ($type === null) {
            throw new InvalidArgumentException("Mapping [{$logicalKey->value}] has unsupported expected_type [{$raw['expected_type']}].");
        }

        return new self(
            logicalKey: $logicalKey,
            columnId: $raw['column_id'],
            expectedType: $type,
            required: (bool) ($raw['required'] ?? false),
            enabled: (bool) ($raw['enabled'] ?? true),
        );
    }

    /**
     * @return array{column_id: string, expected_type: string, required: bool, enabled: bool}
     */
    public function toArray(): array
    {
        return [
            'column_id' => $this->columnId,
            'expected_type' => $this->expectedType->value,
            'required' => $this->required,
            'enabled' => $this->enabled,
        ];
    }
}
