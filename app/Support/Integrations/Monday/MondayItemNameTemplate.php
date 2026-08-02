<?php

namespace App\Support\Integrations\Monday;

use App\Enums\MondayIntakeLogicalKey;
use InvalidArgumentException;

/**
 * Validates item-name templates against approved placeholders only.
 */
final class MondayItemNameTemplate
{
    public const DEFAULT = '{quote_number} — {company_name}';

    public const MAX_LENGTH = 191;

    /**
     * @return list<string>
     */
    public static function allowedPlaceholders(): array
    {
        return [
            '{'.MondayIntakeLogicalKey::QuoteNumber->value.'}',
            '{'.MondayIntakeLogicalKey::CompanyName->value.'}',
            '{'.MondayIntakeLogicalKey::Organization->value.'}',
            '{'.MondayIntakeLogicalKey::RevisionNumber->value.'}',
        ];
    }

    public static function assertValid(string $template): string
    {
        $trimmed = trim($template);

        if ($trimmed === '' || strlen($trimmed) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                'Item name template must be a non-blank string up to '.self::MAX_LENGTH.' characters.',
            );
        }

        preg_match_all('/\{[a-z0-9_]+\}/', $trimmed, $matches);
        $found = $matches[0];
        $allowed = self::allowedPlaceholders();

        foreach ($found as $placeholder) {
            if (! in_array($placeholder, $allowed, true)) {
                throw new InvalidArgumentException("Item name template contains unknown placeholder [{$placeholder}].");
            }
        }

        $forbidden = [
            'requested_due_date',
            'expiration_date',
            'due_date',
            'production_due',
        ];

        foreach ($forbidden as $needle) {
            if (str_contains($trimmed, $needle)) {
                throw new InvalidArgumentException(
                    'Item name template must not reference quote expiration or production due-date fields.',
                );
            }
        }

        return $trimmed;
    }
}
