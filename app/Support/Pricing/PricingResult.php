<?php

namespace App\Support\Pricing;

use App\Enums\OverheadMode;
use App\Enums\PricingMethod;

/**
 * Immutable pricing calculation output.
 *
 * Does not expose permission/authorization decisions — only product pricing facts.
 *
 * Later OrganizationProduct pricing mutations and quote price overrides must be audited;
 * this pure calculation emits no audit events.
 */
final readonly class PricingResult
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public int $materialCostMicroUnits,
        public int $laborCostMicroUnits,
        public int $overheadCostMicroUnits,
        public int $totalUnitCostMicroUnits,
        public OverheadMode $overheadMode,
        public int $overheadAmountMicroUnits,
        public int $overheadRateBasisPoints,
        public PricingMethod $pricingMethod,
        public int $markupBasisPoints,
        public int $targetMarginBasisPoints,
        public ?int $fixedPriceCents,
        public int $calculatedUnitPriceCents,
        public int $finalUnitPriceCents,
        public bool $overrideApplied,
        public ?int $requestedOverridePriceCents,
        public ?int $minimumPriceCents,
        public bool $belowMinimum,
        public bool $approvalRequired,
        public string $quantity,
        public int $extendedPriceCents,
        public string $currencyCode,
        public int $pricingVersion,
        public array $warnings,
    ) {}
}
