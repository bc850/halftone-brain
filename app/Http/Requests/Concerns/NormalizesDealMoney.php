<?php

namespace App\Http\Requests\Concerns;

use App\Support\Money;

trait NormalizesDealMoney
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeDealMoney(array $data): array
    {
        if (array_key_exists('amount', $data) && $data['amount'] !== null && $data['amount'] !== '') {
            $data['amount_cents'] = Money::dollarsToCents((string) $data['amount']);
        } else {
            $data['amount_cents'] = null;
        }

        unset($data['amount']);

        return $data;
    }
}
