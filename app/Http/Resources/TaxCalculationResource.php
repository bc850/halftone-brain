<?php

namespace App\Http\Resources;

use App\Models\QuoteRevisionTaxCalculation;
use App\Support\Money;

/**
 * One append-only tax calculation version.
 *
 * History exists to explain a figure, so it carries the rate, jurisdiction, and
 * redacted certificate reference that produced it. It never carries the actor's
 * identity or the certificate evidence itself: who ran a calculation belongs to
 * the audit trail, and the evidence belongs to the certificate record.
 */
final class TaxCalculationResource
{
    /**
     * @param  iterable<int, QuoteRevisionTaxCalculation>  $calculations
     * @return list<array<string, mixed>>
     */
    public static function collection(iterable $calculations): array
    {
        $payload = [];

        foreach ($calculations as $calculation) {
            $payload[] = self::make($calculation);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function make(?QuoteRevisionTaxCalculation $calculation): ?array
    {
        if ($calculation === null) {
            return null;
        }

        return [
            'id' => $calculation->id,
            'calculation_version' => $calculation->calculation_version,
            'outcome' => $calculation->outcome->value,
            'is_resolved' => $calculation->outcome->isResolved(),
            'taxable_basis' => Money::centsToDollars($calculation->taxable_basis_cents),
            'taxable_basis_cents' => $calculation->taxable_basis_cents,
            'rate_ppm' => $calculation->rate_ppm,
            'rate_percent' => $calculation->rate_ppm === null
                ? null
                : Money::ratePartsPerMillionToPercent($calculation->rate_ppm),
            'tax' => Money::centsToDollars($calculation->tax_cents),
            'tax_cents' => $calculation->tax_cents,
            'jurisdiction' => self::jurisdiction($calculation),
            'source' => $calculation->source->value,
            'is_override' => $calculation->is_override,
            'override_reason' => $calculation->override_reason,
            'certificate_reference' => self::certificateReference($calculation),
            'calculator_version' => $calculation->calculator_version,
            'calculated_at' => $calculation->calculated_at->toIso8601String(),
        ];
    }

    /**
     * @return array{jurisdiction_code: string|null, display_name: string|null, state: string|null}|null
     */
    private static function jurisdiction(QuoteRevisionTaxCalculation $calculation): ?array
    {
        $snapshot = $calculation->jurisdiction_snapshot_json;

        if (! is_array($snapshot)) {
            return null;
        }

        return [
            'jurisdiction_code' => self::text($snapshot['jurisdiction_code'] ?? null),
            'display_name' => self::text($snapshot['display_name'] ?? null),
            'state' => self::text($snapshot['state'] ?? null),
        ];
    }

    private static function certificateReference(QuoteRevisionTaxCalculation $calculation): ?string
    {
        $snapshot = $calculation->certificate_evidence_snapshot_json;

        if (! is_array($snapshot)) {
            return null;
        }

        return self::text($snapshot['certificate_reference'] ?? null);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
