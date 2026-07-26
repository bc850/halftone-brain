<?php

namespace App\Support\Quotes;

/**
 * Approved quote number sequence definitions for operational sync (not auto-run on primary in 2A).
 */
final class QuoteNumberSequenceDefinitions
{
    public const KEY = 'quote';

    public const PAD_LENGTH = 5;

    /**
     * @return array<string, array{prefix: string, pad_length: int}>
     */
    public static function byOrganizationSlug(): array
    {
        return [
            'pelican-signs' => [
                'prefix' => 'PEL-Q-',
                'pad_length' => self::PAD_LENGTH,
            ],
            'brim-drinkware' => [
                'prefix' => 'BRIM-Q-',
                'pad_length' => self::PAD_LENGTH,
            ],
        ];
    }

    /**
     * @return array{prefix: string, pad_length: int}|null
     */
    public static function forOrganizationSlug(string $slug): ?array
    {
        return self::byOrganizationSlug()[$slug] ?? null;
    }
}
