<?php

namespace App\Enums;

enum PermissionEffect: string
{
    case Allow = 'allow';
    case Deny = 'deny';

    public function label(): string
    {
        return match ($this) {
            self::Allow => 'Allow',
            self::Deny => 'Deny',
        };
    }
}
