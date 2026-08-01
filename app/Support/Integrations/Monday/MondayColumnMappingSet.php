<?php

namespace App\Support\Integrations\Monday;

use App\Enums\MondayIntakeLogicalKey;
use InvalidArgumentException;

/**
 * Validated structured Monday column mapping set (no live board checks in 2E.3A).
 */
final readonly class MondayColumnMappingSet
{
    /**
     * @param  array<string, MondayColumnMappingEntry>  $entries
     */
    private function __construct(
        public array $entries,
    ) {}

    /**
     * @param  array<mixed, mixed>|null  $raw
     */
    public static function fromArray(?array $raw): self
    {
        if ($raw === null) {
            return new self([]);
        }

        $entries = [];
        $columnIds = [];

        foreach ($raw as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Column mapping keys must be strings.');
            }

            $logicalKey = MondayIntakeLogicalKey::tryFrom($key);

            if ($logicalKey === null) {
                throw new InvalidArgumentException("Unknown Monday mapping logical key [{$key}].");
            }

            if (! is_array($value)) {
                throw new InvalidArgumentException("Mapping [{$key}] must be an object/array.");
            }

            /** @var array{column_id?: mixed, expected_type?: mixed, required?: mixed, enabled?: mixed} $value */
            $entry = MondayColumnMappingEntry::fromArray($logicalKey, $value);

            if (isset($columnIds[$entry->columnId])) {
                throw new InvalidArgumentException(
                    "Duplicate Monday column ID [{$entry->columnId}] is not permitted in v1 mappings.",
                );
            }

            $columnIds[$entry->columnId] = true;
            $entries[$logicalKey->value] = $entry;
        }

        return new self($entries);
    }

    public function get(MondayIntakeLogicalKey $key): ?MondayColumnMappingEntry
    {
        return $this->entries[$key->value] ?? null;
    }

    /**
     * Structural readiness for activation (no live Monday board inspection).
     *
     * @param  array<string, mixed>|null  $statusLabelMappings
     */
    public function validateForActivation(?array $statusLabelMappings): MondayConfigurationValidationResult
    {
        $errors = [];

        foreach (MondayIntakeLogicalKey::requiredForActivation() as $requiredKey) {
            $entry = $this->get($requiredKey);

            if ($entry === null || ! $entry->enabled) {
                $errors[] = "Required mapping [{$requiredKey->value}] is missing or disabled.";

                continue;
            }

            if (! $entry->required) {
                $errors[] = "Required mapping [{$requiredKey->value}] must be marked required.";
            }
        }

        $intakeLabel = $statusLabelMappings['intake_status'] ?? null;

        if (! is_string($intakeLabel) || trim($intakeLabel) === '') {
            $errors[] = 'status_label_mappings_json.intake_status must define the configured intake label.';
        } elseif (strlen($intakeLabel) > 64) {
            $errors[] = 'status_label_mappings_json.intake_status must be at most 64 characters.';
        }

        if ($errors === []) {
            return MondayConfigurationValidationResult::valid();
        }

        return MondayConfigurationValidationResult::invalid($errors);
    }

    /**
     * @return array<string, array{column_id: string, expected_type: string, required: bool, enabled: bool}>
     */
    public function toArray(): array
    {
        $out = [];

        foreach ($this->entries as $key => $entry) {
            $out[$key] = $entry->toArray();
        }

        return $out;
    }
}
