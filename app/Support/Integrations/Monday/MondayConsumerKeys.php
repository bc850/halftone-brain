<?php

namespace App\Support\Integrations\Monday;

/**
 * Stable Monday consumer keys. Do not register production Monday consumers until
 * a later checkpoint explicitly activates them.
 */
final class MondayConsumerKeys
{
    public const CREATE_INTAKE_ITEM = 'monday.create_intake_item';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::CREATE_INTAKE_ITEM,
        ];
    }
}
