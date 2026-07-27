<?php

namespace App\Http\Resources;

use App\Models\OrganizationProduct;
use App\Models\QuoteRevisionLineItem;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Money;

/**
 * Internal (staff-facing) quote line payload.
 *
 * Cost and margin keys are omitted entirely when the viewer lacks cost visibility —
 * never emitted as null, so the client cannot distinguish "no cost" from "redacted".
 */
final class QuoteLineItemResource
{
    /**
     * @param  iterable<int, QuoteRevisionLineItem>  $lines
     * @param  array<int, OrganizationProduct>  $liveCatalog  Keyed by organization product id.
     * @return list<array<string, mixed>>
     */
    public static function collection(iterable $lines, bool $canViewCost, array $liveCatalog = []): array
    {
        $payload = [];

        foreach ($lines as $line) {
            $payload[] = self::make(
                $line,
                $canViewCost,
                $line->organization_product_id === null
                    ? null
                    : ($liveCatalog[$line->organization_product_id] ?? null),
            );
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function make(
        QuoteRevisionLineItem $line,
        bool $canViewCost,
        ?OrganizationProduct $liveProduct = null,
    ): array {
        $payload = [
            'id' => $line->id,
            'position' => $line->position,
            'line_type' => $line->line_type->value,
            'is_financial' => $line->line_type->isFinancial(),
            'organization_product_id' => $line->organization_product_id,
            'product_id' => $line->product_id,
            'sku_snapshot' => $line->sku_snapshot,
            'name_snapshot' => $line->name_snapshot,
            'customer_description_snapshot' => $line->customer_description_snapshot,
            'internal_description_snapshot' => $line->internal_description_snapshot,
            'item_kind_snapshot' => $line->item_kind_snapshot,
            'quantity' => self::quantity($line),
            'quantity_scaled' => $line->quantity_scaled,
            'uom_snapshot' => $line->uom_snapshot,
            'calculated_unit_price' => self::dollars($line->calculated_unit_price_cents),
            'calculated_unit_price_cents' => $line->calculated_unit_price_cents,
            'final_unit_price' => self::dollars($line->final_unit_price_cents),
            'final_unit_price_cents' => $line->final_unit_price_cents,
            'extended_price' => self::dollars($line->extended_price_cents),
            'line_discount_method' => $line->line_discount_method->value,
            'line_discount_value' => $line->line_discount_value,
            'line_discount_amount' => self::dollars($line->line_discount_amount_cents),
            'net_line_total' => self::dollars($line->net_line_total_cents),
            'net_line_total_cents' => $line->net_line_total_cents,
            'is_taxable' => $line->is_taxable,
            'price_override' => $line->price_override,
            'override_reason' => $line->override_reason,
            'below_minimum' => $line->below_minimum,
            'approval_required' => $line->approval_required,
            'approval_reasons' => self::reasons($line->approval_reason_json),
            'pricing_version_snapshot' => $line->pricing_version_snapshot,
            'components_version_snapshot' => $line->components_version_snapshot,
            'catalog_stale' => self::isCatalogStale($line, $liveProduct),
        ];

        if (! $canViewCost) {
            return $payload;
        }

        $unitCostCents = $line->total_cost_micro_units === null
            ? null
            : Money::microUnitsToCents($line->total_cost_micro_units);

        $payload['material_cost'] = self::microDollars($line->material_cost_micro_units);
        $payload['labor_cost'] = self::microDollars($line->labor_cost_micro_units);
        $payload['overhead_cost'] = self::microDollars($line->overhead_cost_micro_units);
        $payload['unit_cost'] = self::microDollars($line->total_cost_micro_units);
        $payload['extended_cost'] = self::dollars(self::extendedCostCents($line, $unitCostCents));
        $payload['margin_amount'] = self::dollars(self::marginCents($line, $unitCostCents));
        $payload['margin_percent'] = self::marginPercent($line, $unitCostCents);

        return $payload;
    }

    /**
     * Extended cost of a financial line at the snapshotted unit cost.
     */
    public static function extendedCostCents(QuoteRevisionLineItem $line, ?int $unitCostCents): ?int
    {
        if ($unitCostCents === null || $line->quantity_scaled < 1) {
            return null;
        }

        return Money::multiplyCentsByQuantity(
            $unitCostCents,
            ComponentCostEstimator::scaledToQuantity($line->quantity_scaled),
            ComponentCostEstimator::QUANTITY_SCALE,
        );
    }

    private static function marginCents(QuoteRevisionLineItem $line, ?int $unitCostCents): ?int
    {
        $extendedCost = self::extendedCostCents($line, $unitCostCents);

        if ($extendedCost === null) {
            return null;
        }

        return $line->net_line_total_cents - $extendedCost;
    }

    private static function marginPercent(QuoteRevisionLineItem $line, ?int $unitCostCents): ?string
    {
        $margin = self::marginCents($line, $unitCostCents);

        if ($margin === null || $line->net_line_total_cents <= 0) {
            return null;
        }

        return bcdiv(bcmul((string) $margin, '100', 4), (string) $line->net_line_total_cents, 2);
    }

    private static function isCatalogStale(QuoteRevisionLineItem $line, ?OrganizationProduct $liveProduct): bool
    {
        if ($liveProduct === null) {
            return false;
        }

        return $line->pricing_version_snapshot !== $liveProduct->pricing_version
            || $line->components_version_snapshot !== $liveProduct->components_version;
    }

    private static function quantity(QuoteRevisionLineItem $line): ?string
    {
        return $line->quantity_scaled < 1
            ? null
            : ComponentCostEstimator::scaledToQuantity($line->quantity_scaled);
    }

    /**
     * @param  array<string, mixed>|null  $approvalReasonJson
     * @return list<string>
     */
    private static function reasons(?array $approvalReasonJson): array
    {
        $reasons = $approvalReasonJson['reasons'] ?? null;

        if (! is_array($reasons)) {
            return [];
        }

        return array_values(array_filter($reasons, static fn (mixed $reason): bool => is_string($reason)));
    }

    private static function dollars(?int $cents): ?string
    {
        if ($cents === null) {
            return null;
        }

        return $cents < 0
            ? '-'.Money::centsToDollars(abs($cents))
            : Money::centsToDollars($cents);
    }

    private static function microDollars(?int $microUnits): ?string
    {
        return $microUnits === null ? null : Money::microUnitsToDollars($microUnits);
    }
}
