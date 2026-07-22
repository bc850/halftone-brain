<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Salesman = 'salesman';
    case ProjectManager = 'project_manager';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Salesman => 'Salesman',
            self::ProjectManager => 'Project Manager',
        };
    }
}
