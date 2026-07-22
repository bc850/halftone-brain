<?php

namespace App\Rules;

use App\Enums\UserRole;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

final class AssignableOwner
{
    public static function rule(): Exists
    {
        return Rule::exists('users', 'id')->whereIn('role', [
            UserRole::Salesman->value,
            UserRole::Admin->value,
        ]);
    }
}
