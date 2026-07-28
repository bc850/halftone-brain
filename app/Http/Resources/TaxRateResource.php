<?php

namespace App\Http\Resources;

use App\Models\OrganizationTaxRate;
use App\Support\Money;

/**
 * A configured jurisdiction rate.
 *
 * Rates are stored in parts per million so no rate ever passes through a float;
 * the display percentage is derived here rather than in the browser.
 */
final class TaxRateResource
{
    /**
     * @param  iterable<int, OrganizationTaxRate>  $rates
     * @return list<array<string, mixed>>
     */
    public static function collection(iterable $rates): array
    {
        $payload = [];

        foreach ($rates as $rate) {
            $payload[] = self::make($rate);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function make(OrganizationTaxRate $rate): array
    {
        return [
            'id' => $rate->id,
            'jurisdiction_code' => $rate->jurisdiction_code,
            'display_name' => $rate->display_name,
            'country' => $rate->country,
            'state' => $rate->state,
            'county' => $rate->county,
            'city' => $rate->city,
            'postal_code' => $rate->postal_code,
            'rate_ppm' => $rate->rate_ppm,
            'rate_percent' => Money::ratePartsPerMillionToPercent($rate->rate_ppm),
            'effective_from' => $rate->effective_from->toDateString(),
            'effective_through' => $rate->effective_through?->toDateString(),
            'is_active' => $rate->is_active,
            'source_note' => $rate->source_note,
            'created_at' => $rate->created_at?->toIso8601String(),
        ];
    }
}
