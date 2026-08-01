<?php

use App\Support\Tenancy\RbacDefinitions;

test('phase 2e2 rbac definitions add outbox operation permissions with the intended role split', function () {
    $keys = [
        'integrations.outbox.view',
        'integrations.outbox.replay',
        'integrations.outbox.abandon',
    ];

    $permissionKeys = array_column(RbacDefinitions::permissions(), 'key');
    $roles = RbacDefinitions::systemRoles();

    expect(array_values(array_intersect($keys, $permissionKeys)))->toBe($keys)
        ->and(array_values(array_intersect($keys, $roles['owner']['permissions'])))->toBe($keys)
        ->and(array_values(array_intersect($keys, $roles['admin']['permissions'])))->toBe($keys)
        ->and(array_values(array_intersect($keys, $roles['sales_manager']['permissions'])))->toBe([
            'integrations.outbox.view',
        ])
        ->and(array_intersect($keys, $roles['salesperson']['permissions']))->toBe([])
        ->and(array_intersect($keys, $roles['project_manager']['permissions']))->toBe([])
        ->and(array_intersect($keys, $roles['production_worker']['permissions']))->toBe([])
        ->and(array_intersect($keys, $roles['finance']['permissions']))->toBe([])
        ->and(array_intersect($keys, $roles['parent_owner']['permissions'] ?? []))->toBe([])
        ->and(array_intersect($keys, $roles['parent_admin']['permissions'] ?? []))->toBe([])
        ->and(array_intersect($keys, $roles['parent_catalog_manager']['permissions'] ?? []))->toBe([]);
});
