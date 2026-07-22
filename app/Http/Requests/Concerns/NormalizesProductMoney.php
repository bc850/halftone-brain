<?php

namespace App\Http\Requests\Concerns;

use App\Support\Money;

trait NormalizesProductMoney
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeProductMoney(array $data): array
    {
        $trueCostMicroUnits = Money::dollarsToMicroUnits((string) $data['true_cost']);
        $markupBasisPoints = Money::percentToBasisPoints((string) $data['markup_percent']);

        $data['true_cost_micro_units'] = $trueCostMicroUnits;
        $data['markup_basis_points'] = $markupBasisPoints;

        if (array_key_exists('list_price', $data) && $data['list_price'] !== null && $data['list_price'] !== '') {
            $data['list_price_cents'] = Money::dollarsToCents((string) $data['list_price']);
        } else {
            $data['list_price_cents'] = Money::suggestedListPriceCents($trueCostMicroUnits, $markupBasisPoints);
        }

        unset($data['true_cost'], $data['markup_percent'], $data['list_price']);

        return $data;
    }
}
