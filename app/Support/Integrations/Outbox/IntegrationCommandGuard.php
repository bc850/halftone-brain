<?php

namespace App\Support\Integrations\Outbox;

use App\Models\Organization;
use InvalidArgumentException;

final class IntegrationCommandGuard
{
    public function assertDatabase(?string $confirmedDatabase, bool $requireConfirmation = false): string
    {
        $active = (string) config('database.connections.'.config('database.default').'.database');

        if ($requireConfirmation) {
            if ($confirmedDatabase === null || $confirmedDatabase === '') {
                throw new InvalidArgumentException(
                    'Exact database confirmation is required via --confirm-database.',
                );
            }

            if ($confirmedDatabase !== $active) {
                throw new InvalidArgumentException(
                    "Confirmed database [{$confirmedDatabase}] does not match active database [{$active}].",
                );
            }
        }

        return $active;
    }

    public function resolveOrganizationId(?string $organizationSlug): ?int
    {
        if ($organizationSlug === null || $organizationSlug === '') {
            return null;
        }

        $organization = Organization::query()->where('slug', $organizationSlug)->first();

        if ($organization === null) {
            throw new InvalidArgumentException("Organization [{$organizationSlug}] was not found.");
        }

        return (int) $organization->id;
    }
}
