<?php

namespace App\Http\Resources;

use App\Models\QuoteRevisionAdjustment;
use App\Support\Money;

final class QuoteAdjustmentResource
{
    /**
     * @param  iterable<int, QuoteRevisionAdjustment>  $adjustments
     * @return list<array<string, mixed>>
     */
    public static function collection(iterable $adjustments): array
    {
        $payload = [];

        foreach ($adjustments as $adjustment) {
            $payload[] = self::make($adjustment);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function make(QuoteRevisionAdjustment $adjustment): array
    {
        $reasonJson = $adjustment->approval_reason_json ?? [];
        $reason = $reasonJson['reason'] ?? null;
        $reasons = $reasonJson['reasons'] ?? [];

        return [
            'id' => $adjustment->id,
            'position' => $adjustment->position,
            'adjustment_type' => $adjustment->adjustment_type->value,
            'is_discount' => $adjustment->adjustment_type->isDiscount(),
            'description_snapshot' => $adjustment->description_snapshot,
            'method' => $adjustment->method->value,
            'input_value' => $adjustment->input_value,
            'amount' => Money::centsToDollars($adjustment->amount_cents),
            'amount_cents' => $adjustment->amount_cents,
            'is_taxable' => $adjustment->is_taxable,
            'approval_required' => $adjustment->approval_required,
            'approval_reasons' => is_array($reasons)
                ? array_values(array_filter($reasons, static fn (mixed $value): bool => is_string($value)))
                : [],
            'reason' => is_string($reason) ? $reason : null,
        ];
    }
}
