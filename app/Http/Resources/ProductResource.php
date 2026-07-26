<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final class ProductResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Product $product, User $user): array
    {
        $canViewCost = $user->can('viewCost', $product);

        $listPriceCents = $product->list_price_cents;
        $suggestedCents = Money::suggestedListPriceCents(
            $product->true_cost_micro_units,
            $product->markup_basis_points,
        );

        $payload = [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'unit_of_measure' => $product->unit_of_measure->value,
            'description' => $product->description,
            'is_active' => $product->is_active,
            'list_price' => $listPriceCents !== null ? Money::centsToDollars($listPriceCents) : null,
            'suggested_sell_price' => Money::centsToDollars($suggestedCents),
            'category' => $product->relationLoaded('category') && $product->category
                ? ['id' => $product->category->id, 'name' => $product->category->name]
                : null,
        ];

        if ($canViewCost) {
            $payload['true_cost'] = Money::microUnitsToDollars($product->true_cost_micro_units);
            $payload['markup_percent'] = Money::basisPointsToPercent($product->markup_basis_points);
            $payload['notes'] = $product->notes;
        }

        if ($product->relationLoaded('relatedProducts')) {
            $payload['related_products'] = $product->relatedProducts
                ->map(fn (Product $related): array => self::related($related, $user))
                ->values()
                ->all();
        }

        return $payload;
    }

    /**
     * @param  EloquentCollection<int, Product>  $products
     * @return list<array<string, mixed>>
     */
    public static function collection(EloquentCollection $products, User $user): array
    {
        return array_values($products->map(fn (Product $product): array => self::make($product, $user))->all());
    }

    /**
     * @return array<string, mixed>
     */
    private static function related(Product $product, User $user): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'list_price' => $product->list_price_cents !== null
                ? Money::centsToDollars($product->list_price_cents)
                : null,
        ];
    }
}
