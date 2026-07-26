<?php

namespace App\Support\Catalog\ComponentCost;

use App\Enums\ItemKind;
use App\Enums\UnitOfMeasure;
use App\Support\Money;
use App\Support\Pricing\PricingCalculator;

/**
 * Pure estimated material-component costing.
 *
 * Operates only on immutable value objects. Performs no HTTP, auth, tenant,
 * Eloquent, database, or audit side effects.
 */
final class ComponentCostEstimator
{
    public const QUANTITY_SCALE = PricingCalculator::QUANTITY_SCALE;

    public const QUANTITY_SCALE_FACTOR = 1_000_000;

    public const MAX_WASTE_BASIS_POINTS = Money::BASIS_POINTS_PER_UNIT;

    private const INTERMEDIATE_SCALE = 24;

    public function estimate(ComponentCostEstimateInput $input): ComponentCostEstimate
    {
        $this->assertParentEligible($input);

        $breakdowns = [];
        $total = 0;

        foreach ($input->components as $component) {
            $breakdown = $this->estimateLine($input, $component);
            $breakdowns[] = $breakdown;
            $total = Money::addMicroUnits($total, $breakdown->estimatedComponentCostMicroUnits);
        }

        return new ComponentCostEstimate(
            breakdowns: $breakdowns,
            totalEstimatedMaterialCostMicroUnits: $total,
            isEstimateOnly: true,
            doesNotConsumeInventory: true,
        );
    }

    /**
     * Convert a validated decimal quantity string to the six-decimal scaled integer.
     */
    public static function quantityToScaled(string $quantity): int
    {
        Money::assertQuantityString($quantity, self::QUANTITY_SCALE);

        $scaled = bcmul($quantity, (string) self::QUANTITY_SCALE_FACTOR, self::QUANTITY_SCALE);

        return self::intFromNumericString(
            self::roundHalfUp($scaled, 0),
            'Quantity scaled value overflows integer range.',
        );
    }

    /**
     * @return numeric-string
     */
    public static function scaledToQuantity(int $quantityScaled): string
    {
        if ($quantityScaled < 1) {
            throw new InvalidComponentCostException('Scaled quantity must be greater than zero.');
        }

        /** @var numeric-string $quantity */
        $quantity = Money::normalizeQuantity(
            bcdiv((string) $quantityScaled, (string) self::QUANTITY_SCALE_FACTOR, self::QUANTITY_SCALE),
            self::QUANTITY_SCALE,
        );

        return $quantity;
    }

    private function estimateLine(
        ComponentCostEstimateInput $parent,
        ComponentLineInput $component,
    ): ComponentCostBreakdown {
        $this->assertComponentEligible($parent, $component);

        $usageQuantity = self::scaledToQuantity($component->quantityScaled);
        $adjustedUsage = $this->applyWaste($usageQuantity, $component->wasteBasisPoints);
        $adjustedScaled = self::intFromNumericString(
            self::roundHalfUp(
                bcmul($adjustedUsage, (string) self::QUANTITY_SCALE_FACTOR, self::INTERMEDIATE_SCALE),
                0,
            ),
            'Waste-adjusted quantity overflows integer range.',
        );

        if ($adjustedScaled < 1) {
            throw new InvalidComponentCostException('Waste-adjusted quantity must be greater than zero.');
        }

        $purchaseUnit = $component->purchaseUnitOfMeasure;
        assert($purchaseUnit instanceof UnitOfMeasure);

        $resolved = $this->resolveUsageToPurchase(
            $component->usageUnitOfMeasure,
            $purchaseUnit,
            $component->conversions,
        );

        $convertedPurchaseQuantity = bcdiv(
            bcmul($adjustedUsage, (string) $resolved['numerator'], self::INTERMEDIATE_SCALE),
            (string) $resolved['denominator'],
            self::INTERMEDIATE_SCALE,
        );

        $purchaseCost = $component->purchaseCostMicroUnits;
        assert(is_int($purchaseCost));

        $rawCost = bcmul($convertedPurchaseQuantity, (string) $purchaseCost, self::INTERMEDIATE_SCALE);
        $lineCost = self::intFromNumericString(
            self::roundHalfUp($rawCost, 0),
            'Component cost overflows integer range.',
        );

        if ($lineCost < 0) {
            throw new InvalidComponentCostException('Component cost cannot be negative.');
        }

        return new ComponentCostBreakdown(
            componentOrganizationProductId: $component->componentOrganizationProductId,
            baseUsageQuantityScaled: $component->quantityScaled,
            wasteBasisPoints: $component->wasteBasisPoints,
            wasteAdjustedQuantityScaled: $adjustedScaled,
            usageUnitOfMeasure: $component->usageUnitOfMeasure,
            convertedPurchaseQuantity: self::trimTrailingZeros($convertedPurchaseQuantity),
            purchaseUnitOfMeasure: $purchaseUnit,
            purchaseCostMicroUnits: $purchaseCost,
            estimatedComponentCostMicroUnits: $lineCost,
            conversionDirection: $resolved['direction'],
        );
    }

    /**
     * @param  numeric-string  $usageQuantity
     * @return numeric-string
     */
    private function applyWaste(string $usageQuantity, int $wasteBasisPoints): string
    {
        if ($wasteBasisPoints < 0 || $wasteBasisPoints > self::MAX_WASTE_BASIS_POINTS) {
            throw new InvalidComponentCostException(
                'Waste basis points must be between 0 and '.self::MAX_WASTE_BASIS_POINTS.'.',
            );
        }

        $factor = bcdiv(
            (string) (Money::BASIS_POINTS_PER_UNIT + $wasteBasisPoints),
            (string) Money::BASIS_POINTS_PER_UNIT,
            self::INTERMEDIATE_SCALE,
        );

        return bcmul($usageQuantity, $factor, self::INTERMEDIATE_SCALE);
    }

    /**
     * Resolve usage→purchase conversion using identical, direct, or reciprocal only.
     *
     * @param  array<int, ComponentConversionInput>  $conversions
     * @return array{numerator: int, denominator: int, direction: ComponentConversionDirection}
     */
    public function resolveUsageToPurchase(
        UnitOfMeasure $usageUnit,
        UnitOfMeasure $purchaseUnit,
        array $conversions,
    ): array {
        if ($usageUnit === $purchaseUnit) {
            return [
                'numerator' => 1,
                'denominator' => 1,
                'direction' => ComponentConversionDirection::Identical,
            ];
        }

        $direct = null;
        $reciprocal = null;

        foreach ($conversions as $conversion) {
            if (! $conversion->isActive) {
                continue;
            }

            if ($conversion->fromUnit === $usageUnit && $conversion->toUnit === $purchaseUnit) {
                if ($direct !== null) {
                    throw new InvalidComponentCostException('Ambiguous direct conversion records.');
                }
                $direct = $conversion;
            }

            if ($conversion->fromUnit === $purchaseUnit && $conversion->toUnit === $usageUnit) {
                if ($reciprocal !== null) {
                    throw new InvalidComponentCostException('Ambiguous reciprocal conversion records.');
                }
                $reciprocal = $conversion;
            }
        }

        if ($direct !== null && $reciprocal !== null) {
            $productA = bcmul((string) $direct->numerator, (string) $reciprocal->numerator, 0);
            $productB = bcmul((string) $direct->denominator, (string) $reciprocal->denominator, 0);

            if (bccomp($productA, $productB, 0) !== 0) {
                throw new InvalidComponentCostException(
                    'Direct and reciprocal conversions disagree.',
                );
            }

            return [
                'numerator' => $direct->numerator,
                'denominator' => $direct->denominator,
                'direction' => ComponentConversionDirection::Direct,
            ];
        }

        if ($direct !== null) {
            return [
                'numerator' => $direct->numerator,
                'denominator' => $direct->denominator,
                'direction' => ComponentConversionDirection::Direct,
            ];
        }

        if ($reciprocal !== null) {
            return [
                'numerator' => $reciprocal->denominator,
                'denominator' => $reciprocal->numerator,
                'direction' => ComponentConversionDirection::Reciprocal,
            ];
        }

        $hasInactiveCover = false;
        foreach ($conversions as $conversion) {
            if ($conversion->isActive) {
                continue;
            }

            $covers = (
                ($conversion->fromUnit === $usageUnit && $conversion->toUnit === $purchaseUnit)
                || ($conversion->fromUnit === $purchaseUnit && $conversion->toUnit === $usageUnit)
            );

            if ($covers) {
                $hasInactiveCover = true;
                break;
            }
        }

        if ($hasInactiveCover) {
            throw new InvalidComponentCostException('Required conversion is inactive.');
        }

        throw new InvalidComponentCostException('Required unit conversion is missing.');
    }

    private function assertParentEligible(ComponentCostEstimateInput $input): void
    {
        if (! in_array($input->itemKind, [ItemKind::Product, ItemKind::Service], true)) {
            throw new InvalidComponentCostException(
                'Only product or service finished items may have estimated components.',
            );
        }

        if (! $input->isSellable) {
            throw new InvalidComponentCostException(
                'Finished item must be sellable to estimate component cost.',
            );
        }
    }

    private function assertComponentEligible(
        ComponentCostEstimateInput $parent,
        ComponentLineInput $component,
    ): void {
        if ($component->parentAccountId !== $parent->parentAccountId
            || $component->organizationId !== $parent->organizationId) {
            throw new InvalidComponentCostException(
                'Component must belong to the same organization and parent account.',
            );
        }

        if ($component->componentOrganizationProductId === $parent->organizationProductId) {
            throw new InvalidComponentCostException(
                'A finished item cannot reference itself as a component.',
            );
        }

        if ($component->quantityScaled < 1) {
            throw new InvalidComponentCostException('Component quantity must be greater than zero.');
        }

        if ($component->wasteBasisPoints < 0
            || $component->wasteBasisPoints > self::MAX_WASTE_BASIS_POINTS) {
            throw new InvalidComponentCostException(
                'Waste basis points must be between 0 and '.self::MAX_WASTE_BASIS_POINTS.'.',
            );
        }

        if ($component->itemKind !== ItemKind::Material) {
            throw new InvalidComponentCostException(
                'Component product master must be material.',
            );
        }

        if (! $component->isPurchasable) {
            throw new InvalidComponentCostException('Component must be purchasable.');
        }

        if ($component->purchaseUnitOfMeasure === null) {
            throw new InvalidComponentCostException('Component purchase unit of measure is required.');
        }

        if ($component->purchaseCostMicroUnits === null) {
            throw new InvalidComponentCostException('Component purchase cost is required.');
        }

        if ($component->purchaseCostMicroUnits < 0) {
            throw new InvalidComponentCostException('Component purchase cost cannot be negative.');
        }
    }

    /**
     * Half-up rounding matching {@see Money} fixed-point conventions.
     *
     * @param  numeric-string  $amount
     * @return numeric-string
     */
    private static function roundHalfUp(string $amount, int $scale): string
    {
        $factor = bcpow('10', (string) $scale);
        $shifted = bcmul($amount, $factor, $scale + 2);

        if (bccomp($amount, '0', $scale + 2) >= 0) {
            $shifted = bcadd($shifted, '0.5', 0);
        } else {
            $shifted = bcsub($shifted, '0.5', 0);
        }

        return bcdiv($shifted, '1', 0);
    }

    /**
     * @param  numeric-string  $value
     */
    private static function intFromNumericString(string $value, string $overflowMessage): int
    {
        if (bccomp($value, (string) PHP_INT_MAX, 0) > 0 || bccomp($value, (string) PHP_INT_MIN, 0) < 0) {
            throw new InvalidComponentCostException($overflowMessage);
        }

        return (int) $value;
    }

    /**
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private static function trimTrailingZeros(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');
        if ($trimmed === '' || $trimmed === '.') {
            return '0';
        }

        self::assertNumericString($trimmed);

        return $trimmed;
    }

    /**
     * @phpstan-assert numeric-string $value
     */
    private static function assertNumericString(string $value): void
    {
        if (! preg_match('/^\d+(\.\d+)?$/', $value)) {
            throw new InvalidComponentCostException('Invalid converted quantity.');
        }
    }
}
