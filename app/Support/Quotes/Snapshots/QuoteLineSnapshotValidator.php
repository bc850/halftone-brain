<?php

namespace App\Support\Quotes\Snapshots;

use App\Enums\QuoteLineType;
use App\Support\Quotes\Totals\InvalidQuoteTotalsException;
use App\Support\Quotes\Totals\QuoteLineCalculationInput;

/**
 * Pure snapshot validation for quote line payloads.
 */
final class QuoteLineSnapshotValidator
{
    public function validate(QuoteLineCalculationInput $line): void
    {
        if ($line->currencyCode !== 'USD') {
            throw new InvalidQuoteTotalsException(
                "Line [{$line->key}] currency [{$line->currencyCode}] must match revision currency USD in 2B.1."
            );
        }

        match ($line->lineType) {
            QuoteLineType::Catalog => $this->validateCatalog($line),
            QuoteLineType::Custom => $this->validateCustom($line),
            QuoteLineType::Section, QuoteLineType::Note => $this->validateNonFinancial($line),
        };

        $this->assertCostScale($line);
    }

    private function validateCatalog(QuoteLineCalculationInput $line): void
    {
        if ($line->productId === null || $line->organizationProductId === null) {
            throw new InvalidQuoteTotalsException(
                "Catalog line [{$line->key}] requires product_id and organization_product_id traceability."
            );
        }

        if ($line->skuSnapshot === null || $line->skuSnapshot === '') {
            throw new InvalidQuoteTotalsException("Catalog line [{$line->key}] requires sku_snapshot.");
        }

        if ($line->pricingVersionSnapshot === null) {
            throw new InvalidQuoteTotalsException("Catalog line [{$line->key}] requires pricing_version_snapshot.");
        }

        if ($line->nameSnapshot === '') {
            throw new InvalidQuoteTotalsException("Catalog line [{$line->key}] requires name_snapshot.");
        }
    }

    private function validateCustom(QuoteLineCalculationInput $line): void
    {
        if ($line->productId !== null || $line->organizationProductId !== null) {
            throw new InvalidQuoteTotalsException(
                "Custom line [{$line->key}] must not reference catalog IDs."
            );
        }

        if ($line->nameSnapshot === '') {
            throw new InvalidQuoteTotalsException("Custom line [{$line->key}] requires name_snapshot.");
        }
    }

    private function validateNonFinancial(QuoteLineCalculationInput $line): void
    {
        if ($line->productId !== null || $line->organizationProductId !== null) {
            throw new InvalidQuoteTotalsException(
                "Section/note line [{$line->key}] must not reference catalog IDs."
            );
        }

        if ($line->totalCostMicroUnits !== null
            || $line->materialCostMicroUnits !== null
            || $line->laborCostMicroUnits !== null
            || $line->overheadCostMicroUnits !== null) {
            throw new InvalidQuoteTotalsException(
                "Section/note line [{$line->key}] must not carry cost snapshots."
            );
        }
    }

    private function assertCostScale(QuoteLineCalculationInput $line): void
    {
        foreach ([
            'material_cost_micro_units' => $line->materialCostMicroUnits,
            'labor_cost_micro_units' => $line->laborCostMicroUnits,
            'overhead_cost_micro_units' => $line->overheadCostMicroUnits,
            'total_cost_micro_units' => $line->totalCostMicroUnits,
        ] as $label => $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidQuoteTotalsException(
                    "Line [{$line->key}] {$label} cannot be negative micro-units."
                );
            }
        }

        foreach ([
            'calculated_unit_price_cents' => $line->calculatedUnitPriceCents,
            'final_unit_price_cents' => $line->finalUnitPriceCents,
        ] as $label => $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidQuoteTotalsException(
                    "Line [{$line->key}] {$label} cannot be negative cents."
                );
            }
        }
    }
}
