<?php

namespace App\Support\Catalog;

use App\Enums\UnitOfMeasure;
use App\Models\OrganizationProduct;
use App\Models\OrganizationProductUnitConversion;
use Illuminate\Support\Collection;

/**
 * Detects when organization unit fields differ without a covering exact conversion.
 * Warning-only for Phase 1C.5 (estimated component costing arrives in 1C.6).
 */
final class IncompleteUnitSetup
{
    public static function warningMessage(): string
    {
        return 'Unit setup is incomplete. Add a conversion before this material can be used for estimated component costing.';
    }

    /**
     * @param  Collection<int, OrganizationProductUnitConversion>|null  $conversions
     */
    public static function applies(
        OrganizationProduct $organizationProduct,
        ?Collection $conversions = null,
    ): bool {
        $units = self::distinctUnits($organizationProduct);

        if (count($units) <= 1) {
            return false;
        }

        $active = ($conversions ?? $organizationProduct->unitConversions)
            ->filter(fn (OrganizationProductUnitConversion $conversion): bool => $conversion->is_active)
            ->values();

        $pairCount = count($units);

        for ($i = 0; $i < $pairCount; $i++) {
            for ($j = $i + 1; $j < $pairCount; $j++) {
                if (! self::hasDirectionalCover($active, $units[$i], $units[$j])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function distinctUnits(OrganizationProduct $organizationProduct): array
    {
        $values = [];

        foreach ([
            $organizationProduct->purchase_unit_of_measure,
            $organizationProduct->stock_unit_of_measure,
            $organizationProduct->usage_unit_of_measure,
        ] as $unit) {
            if ($unit instanceof UnitOfMeasure) {
                $values[$unit->value] = $unit->value;
            }
        }

        return array_values($values);
    }

    /**
     * @param  Collection<int, OrganizationProductUnitConversion>  $conversions
     */
    private static function hasDirectionalCover(Collection $conversions, string $a, string $b): bool
    {
        foreach ($conversions as $conversion) {
            $from = $conversion->from_unit->value;
            $to = $conversion->to_unit->value;

            if (($from === $a && $to === $b) || ($from === $b && $to === $a)) {
                return true;
            }
        }

        return false;
    }
}
