<?php

namespace App\Support\Catalog;

use App\Models\OrganizationProduct;
use App\Support\Money;
use App\Support\Pricing\InvalidPricingException;
use App\Support\Pricing\PricingCalculator;
use App\Support\Quotes\QuoteCatalogLinePricer;
use App\Support\Tenancy\TenantContext;

/**
 * Sellable catalog rows offered to the quote line picker.
 *
 * Mirrors the eligibility rules {@see QuoteCatalogLinePricer::assertSellable()}
 * enforces on save, so the picker cannot surface an item the domain will reject.
 * Minimum price is cost-adjacent and is omitted without cost visibility.
 */
final class CatalogQuoteOptions
{
    public const LIMIT = 100;

    /**
     * @return list<array<string, mixed>>
     */
    public static function sellable(TenantContext $tenant, string $search = ''): array
    {
        $canViewCost = $tenant->canViewCost();

        $products = OrganizationProduct::query()
            ->with('product')
            ->where('organization_id', $tenant->organizationId)
            ->where('parent_account_id', $tenant->parentAccountId)
            ->where('is_sellable', true)
            ->where('is_available', true)
            ->whereHas('product', function ($query) use ($search): void {
                $query->where('is_active', true);

                if ($search !== '') {
                    $query->where(function ($inner) use ($search): void {
                        $inner->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%");
                    });
                }
            })
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        return array_values($products
            ->map(function (OrganizationProduct $organizationProduct) use ($canViewCost): array {
                $calculated = self::unitPriceCents($organizationProduct);

                $payload = [
                    'id' => $organizationProduct->id,
                    'display_name' => $organizationProduct->display_name ?: $organizationProduct->product->name,
                    'sku' => $organizationProduct->product->sku,
                    'unit_of_measure' => $organizationProduct->product->unit_of_measure->value,
                    'currency_code' => $organizationProduct->currency_code,
                    'allow_price_override' => $organizationProduct->allow_price_override,
                    'unit_selling_price' => $calculated === null ? null : Money::centsToDollars($calculated),
                ];

                if ($canViewCost && $organizationProduct->minimum_price_cents !== null) {
                    $payload['minimum_price'] = Money::centsToDollars($organizationProduct->minimum_price_cents);
                }

                return $payload;
            })
            ->all());
    }

    private static function unitPriceCents(OrganizationProduct $organizationProduct): ?int
    {
        try {
            return (new PricingCalculator)
                ->calculate($organizationProduct->toPricingInput())
                ->finalUnitPriceCents;
        } catch (InvalidPricingException) {
            return null;
        }
    }
}
