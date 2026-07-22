<?php

namespace App\Enums;

enum RoleScope: string
{
    case System = 'system';
    case ParentAccount = 'parent_account';
    case Organization = 'organization';

    public function label(): string
    {
        return match ($this) {
            self::System => 'System',
            self::ParentAccount => 'Parent Account',
            self::Organization => 'Organization',
        };
    }
}
