<?php

namespace App\Support\Catalog;

use App\Enums\UnitOfMeasure;
use App\Support\Catalog\ComponentCost\ComponentConversionInput;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Catalog\ComponentCost\InvalidComponentCostException;
use InvalidArgumentException;

/**
 * Pure package-price → effective OrganizationProduct purchase-unit cost normalization.
 *
 * Side-effect free: no Eloquent, HTTP, auth, or audit writes.
 *
 * Steps:
 * 1. price per offering purchase-UOM unit = package price ÷ package quantity
 * 2. convert that unit cost into cost per OrganizationProduct purchase UOM
 * 3. round once, half-up, to Money micro-unit scale
 */
final class VendorPackagePriceNormalizer
{
    private const INTERMEDIATE_SCALE = 24;

    public function __construct(private ComponentCostEstimator $estimator = new ComponentCostEstimator) {}

    /**
     * @param  array<int, ComponentConversionInput>  $conversions
     *
     * @throws InvalidComponentCostException|InvalidArgumentException
     */
    public function normalize(
        int $packagePriceMicroUnits,
        int $packageQuantityScaled,
        UnitOfMeasure $offeringPurchaseUom,
        UnitOfMeasure $organizationPurchaseUom,
        array $conversions,
    ): int {
        if ($packagePriceMicroUnits < 0) {
            throw new InvalidArgumentException('Package price cannot be negative.');
        }

        if ($packageQuantityScaled < 1) {
            throw new InvalidArgumentException('Package quantity must be greater than zero.');
        }

        $packageQuantity = ComponentCostEstimator::scaledToQuantity($packageQuantityScaled);

        /** @var numeric-string $pricePerOfferingUnit */
        $pricePerOfferingUnit = bcdiv(
            (string) $packagePriceMicroUnits,
            $packageQuantity,
            self::INTERMEDIATE_SCALE,
        );

        $resolved = $this->estimator->resolveUsageToPurchase(
            $offeringPurchaseUom,
            $organizationPurchaseUom,
            $conversions,
        );

        // 1 offering unit = num/den OP purchase units → cost_per_OP = cost_per_offering * den / num
        /** @var numeric-string $costPerOrganizationUnit */
        $costPerOrganizationUnit = bcdiv(
            bcmul($pricePerOfferingUnit, (string) $resolved['denominator'], self::INTERMEDIATE_SCALE),
            (string) $resolved['numerator'],
            self::INTERMEDIATE_SCALE,
        );

        if (bccomp($costPerOrganizationUnit, '0', self::INTERMEDIATE_SCALE) < 0) {
            throw new InvalidArgumentException('Effective purchase cost cannot be negative.');
        }

        $rounded = $this->roundHalfUpToInteger($costPerOrganizationUnit);

        if (bccomp((string) $rounded, (string) PHP_INT_MAX, 0) > 0) {
            throw new InvalidArgumentException('Effective purchase cost overflows integer range.');
        }

        return (int) $rounded;
    }

    /**
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private function roundHalfUpToInteger(string $value): string
    {
        $rounded = $this->bcRoundHalfUp($value, 0);

        return $rounded;
    }

    /**
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private function bcRoundHalfUp(string $value, int $scale): string
    {
        $factor = bcpow('10', (string) $scale, 0);
        $scaled = bcmul($value, $factor, self::INTERMEDIATE_SCALE);
        $negative = bccomp($scaled, '0', self::INTERMEDIATE_SCALE) < 0;
        $absolute = $negative ? bcmul($scaled, '-1', self::INTERMEDIATE_SCALE) : $scaled;
        $whole = bcdiv($absolute, '1', 0);
        $fraction = bcsub($absolute, $whole, self::INTERMEDIATE_SCALE);

        if (bccomp($fraction, '0.5', self::INTERMEDIATE_SCALE) >= 0) {
            $whole = bcadd($whole, '1', 0);
        }

        $signed = $negative ? bcmul($whole, '-1', 0) : $whole;

        if ($scale === 0) {
            return $signed;
        }

        return bcdiv($signed, $factor, $scale);
    }
}
