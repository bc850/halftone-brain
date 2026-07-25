<?php

namespace App\Support\Pricing;

use App\Enums\OverheadMode;
use App\Enums\PricingMethod;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Pure pricing calculation engine.
 *
 * No HTTP, auth, TenantContext, sessions, or database writes.
 * Formulas are independently unit-testable from OrganizationProduct mapping.
 *
 * Audit boundary: this calculator emits no audit events. Later OrganizationProduct
 * pricing mutations and quote price overrides must be audited in 1C+.
 */
final class PricingCalculator
{
    /**
     * Maximum fractional digits accepted for quantity strings.
     *
     * Supports values such as "1", "12", "2.5", and "10.125".
     */
    public const QUANTITY_SCALE = 6;

    public const CURRENCY_USD = 'USD';

    public function calculate(PricingInput $input): PricingResult
    {
        $this->assertConfiguration($input);

        $quantity = Money::normalizeQuantity($input->quantity, self::QUANTITY_SCALE);

        $overhead = $this->overheadMicroUnits($input);
        $totalCost = Money::addMicroUnits(
            Money::addMicroUnits($input->materialCostMicroUnits, $input->laborCostMicroUnits),
            $overhead,
        );

        $calculatedUnitPriceCents = $this->calculatedUnitPriceCents($input, $totalCost);

        $overrideApplied = false;
        $finalUnitPriceCents = $calculatedUnitPriceCents;

        if ($input->requestedOverridePriceCents !== null) {
            if (! $input->allowPriceOverride) {
                throw new InvalidPricingException(
                    'Price override is not allowed for this organization product.'
                );
            }

            if ($input->requestedOverridePriceCents < 0) {
                throw new InvalidPricingException('Override price cannot be negative.');
            }

            $overrideApplied = true;
            $finalUnitPriceCents = $input->requestedOverridePriceCents;
        }

        $belowMinimum = $input->minimumPriceCents !== null
            && $finalUnitPriceCents < $input->minimumPriceCents;

        $warnings = [];
        if ($belowMinimum) {
            $warnings[] = 'Final unit price is below the configured minimum price.';
        }

        try {
            $extendedPriceCents = Money::multiplyCentsByQuantity(
                $finalUnitPriceCents,
                $quantity,
                self::QUANTITY_SCALE,
            );
        } catch (InvalidArgumentException $exception) {
            throw new InvalidPricingException($exception->getMessage(), 0, $exception);
        }

        return new PricingResult(
            materialCostMicroUnits: $input->materialCostMicroUnits,
            laborCostMicroUnits: $input->laborCostMicroUnits,
            overheadCostMicroUnits: $overhead,
            totalUnitCostMicroUnits: $totalCost,
            overheadMode: $input->overheadMode,
            overheadAmountMicroUnits: $input->overheadAmountMicroUnits,
            overheadRateBasisPoints: $input->overheadRateBasisPoints,
            pricingMethod: $input->pricingMethod,
            markupBasisPoints: $input->markupBasisPoints,
            targetMarginBasisPoints: $input->targetMarginBasisPoints,
            fixedPriceCents: $input->fixedPriceCents,
            calculatedUnitPriceCents: $calculatedUnitPriceCents,
            finalUnitPriceCents: $finalUnitPriceCents,
            overrideApplied: $overrideApplied,
            requestedOverridePriceCents: $input->requestedOverridePriceCents,
            minimumPriceCents: $input->minimumPriceCents,
            belowMinimum: $belowMinimum,
            approvalRequired: $belowMinimum,
            quantity: $quantity,
            extendedPriceCents: $extendedPriceCents,
            currencyCode: $input->currencyCode,
            pricingVersion: $input->pricingVersion,
            warnings: $warnings,
        );
    }

    private function assertConfiguration(PricingInput $input): void
    {
        if ($input->currencyCode !== self::CURRENCY_USD) {
            throw new InvalidPricingException('Only USD currency is supported.');
        }

        if ($input->pricingVersion < 1) {
            throw new InvalidPricingException('Pricing version must be at least 1.');
        }

        foreach ([
            'material cost' => $input->materialCostMicroUnits,
            'labor cost' => $input->laborCostMicroUnits,
            'overhead amount' => $input->overheadAmountMicroUnits,
            'overhead rate' => $input->overheadRateBasisPoints,
            'markup' => $input->markupBasisPoints,
            'target margin' => $input->targetMarginBasisPoints,
        ] as $label => $value) {
            if ($value < 0) {
                throw new InvalidPricingException("{$label} cannot be negative.");
            }
        }

        if ($input->fixedPriceCents !== null && $input->fixedPriceCents < 0) {
            throw new InvalidPricingException('Fixed price cannot be negative.');
        }

        if ($input->minimumPriceCents !== null && $input->minimumPriceCents < 0) {
            throw new InvalidPricingException('Minimum price cannot be negative.');
        }

        $this->assertOverheadInputs($input);
        $this->assertPricingMethodInputs($input);

        try {
            Money::assertQuantityString($input->quantity, self::QUANTITY_SCALE);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidPricingException($exception->getMessage(), 0, $exception);
        }
    }

    private function assertOverheadInputs(PricingInput $input): void
    {
        match ($input->overheadMode) {
            OverheadMode::None => $this->requireZero(
                [
                    'overhead amount' => $input->overheadAmountMicroUnits,
                    'overhead rate' => $input->overheadRateBasisPoints,
                ],
                'When overhead mode is none, overhead amount and rate must be zero.',
            ),
            OverheadMode::Fixed => $this->requireZero(
                ['overhead rate' => $input->overheadRateBasisPoints],
                'When overhead mode is fixed, overhead rate must be zero.',
            ),
            OverheadMode::Rate => $this->requireZero(
                ['overhead amount' => $input->overheadAmountMicroUnits],
                'When overhead mode is rate, fixed overhead amount must be zero.',
            ),
        };
    }

    private function assertPricingMethodInputs(PricingInput $input): void
    {
        match ($input->pricingMethod) {
            PricingMethod::Markup => $this->assertMarkupMethod($input),
            PricingMethod::TargetMargin => $this->assertTargetMarginMethod($input),
            PricingMethod::Fixed => $this->assertFixedMethod($input),
        };
    }

    private function assertMarkupMethod(PricingInput $input): void
    {
        if ($input->targetMarginBasisPoints !== 0) {
            throw new InvalidPricingException(
                'When pricing method is markup, target margin must be zero.'
            );
        }

        if ($input->fixedPriceCents !== null) {
            throw new InvalidPricingException(
                'When pricing method is markup, fixed price must be null.'
            );
        }
    }

    private function assertTargetMarginMethod(PricingInput $input): void
    {
        if ($input->markupBasisPoints !== 0) {
            throw new InvalidPricingException(
                'When pricing method is target margin, markup must be zero.'
            );
        }

        if ($input->fixedPriceCents !== null) {
            throw new InvalidPricingException(
                'When pricing method is target margin, fixed price must be null.'
            );
        }

        if ($input->targetMarginBasisPoints >= Money::BASIS_POINTS_PER_UNIT) {
            throw new InvalidPricingException('Target margin must be below 100%.');
        }
    }

    private function assertFixedMethod(PricingInput $input): void
    {
        if ($input->fixedPriceCents === null) {
            throw new InvalidPricingException(
                'When pricing method is fixed, fixed price is required.'
            );
        }

        if ($input->markupBasisPoints !== 0 || $input->targetMarginBasisPoints !== 0) {
            throw new InvalidPricingException(
                'When pricing method is fixed, markup and target margin must be zero.'
            );
        }
    }

    /**
     * @param  array<string, int>  $values
     */
    private function requireZero(array $values, string $message): void
    {
        foreach ($values as $value) {
            if ($value !== 0) {
                throw new InvalidPricingException($message);
            }
        }
    }

    private function overheadMicroUnits(PricingInput $input): int
    {
        try {
            return match ($input->overheadMode) {
                OverheadMode::None => 0,
                OverheadMode::Fixed => $input->overheadAmountMicroUnits,
                OverheadMode::Rate => Money::applyBasisPointsToMicroUnits(
                    Money::addMicroUnits($input->materialCostMicroUnits, $input->laborCostMicroUnits),
                    $input->overheadRateBasisPoints,
                ),
            };
        } catch (InvalidArgumentException $exception) {
            throw new InvalidPricingException($exception->getMessage(), 0, $exception);
        }
    }

    private function calculatedUnitPriceCents(PricingInput $input, int $totalCostMicroUnits): int
    {
        try {
            return match ($input->pricingMethod) {
                PricingMethod::Markup => Money::sellCentsFromMarkup(
                    $totalCostMicroUnits,
                    $input->markupBasisPoints,
                ),
                PricingMethod::TargetMargin => Money::sellCentsFromTargetMargin(
                    $totalCostMicroUnits,
                    $input->targetMarginBasisPoints,
                ),
                PricingMethod::Fixed => (int) $input->fixedPriceCents,
            };
        } catch (InvalidArgumentException $exception) {
            throw new InvalidPricingException($exception->getMessage(), 0, $exception);
        }
    }
}
