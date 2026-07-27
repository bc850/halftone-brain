<?php

namespace App\Support\Quotes\Totals;

use App\Enums\QuoteLineDiscountMethod;
use App\Enums\QuoteLineType;
use InvalidArgumentException;

/**
 * Immutable line input for pure totals calculation.
 */
final readonly class QuoteLineCalculationInput
{
    /**
     * @param  list<string>|null  $approvalReasons
     * @param  array<string, mixed>|null  $componentCostBreakdown
     * @param  array<string, mixed>|null  $pricingInputSnapshot
     * @param  array<string, mixed>|null  $pricingResultSnapshot
     */
    public function __construct(
        public string $key,
        public QuoteLineType $lineType,
        public string $nameSnapshot,
        public ?string $customerDescriptionSnapshot,
        public ?string $internalDescriptionSnapshot,
        public ?int $productId,
        public ?int $organizationProductId,
        public ?string $skuSnapshot,
        public ?string $itemKindSnapshot,
        public int $quantityScaled,
        public ?string $uomSnapshot,
        public ?int $calculatedUnitPriceCents,
        public ?int $finalUnitPriceCents,
        public QuoteLineDiscountMethod $lineDiscountMethod,
        public int $lineDiscountValue,
        public bool $isTaxable,
        public bool $priceOverride,
        public ?string $overrideReason,
        public bool $belowMinimum,
        public bool $approvalRequired,
        public ?array $approvalReasons,
        public ?int $materialCostMicroUnits,
        public ?int $laborCostMicroUnits,
        public ?int $overheadCostMicroUnits,
        public ?int $totalCostMicroUnits,
        public ?string $pricingMethodSnapshot,
        public ?int $markupBasisPointsSnapshot,
        public ?int $marginBasisPointsSnapshot,
        public ?int $pricingVersionSnapshot,
        public ?int $componentsVersionSnapshot,
        public ?array $componentCostBreakdown,
        public ?array $pricingInputSnapshot,
        public ?array $pricingResultSnapshot,
        public string $currencyCode = 'USD',
    ) {
        if ($this->key === '') {
            throw new InvalidArgumentException('Line key is required.');
        }
    }
}
