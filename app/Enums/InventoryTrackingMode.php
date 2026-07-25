<?php

namespace App\Enums;

/**
 * Organization inventory tracking policy for an OrganizationProduct.
 *
 * Phase 1 allows only {@see self::None} and {@see self::PeriodicExternal}.
 * A future perpetual_internal mode is reserved for a separately approved inventory phase
 * and must not be persisted by application code until then.
 */
enum InventoryTrackingMode: string
{
    case None = 'none';
    case PeriodicExternal = 'periodic_external';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::PeriodicExternal => 'Periodic external',
        };
    }
}
