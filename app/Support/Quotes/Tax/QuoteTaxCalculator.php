<?php

namespace App\Support\Quotes\Tax;

use App\Enums\QuoteTaxCalculationOutcome;
use App\Enums\QuoteTaxCalculationSource;
use App\Support\Money;
use App\Support\Tax\OrganizationCompanyTaxCertificateApplicability;

/**
 * Pure tax decision engine.
 *
 * Given a taxable basis and the facts the caller resolved, it produces exactly
 * one of three outcomes:
 *
 * - calculated: a configured rate applied, tax = round_half_up(basis × rate_ppm / 1,000,000)
 * - exempt: tax is zero because verified, in-window, matching-jurisdiction evidence exists
 * - review_required: anything else — missing rate, missing or ambiguous jurisdiction,
 *   or certificate evidence that is absent, unverified, expired, rejected, revoked,
 *   or issued for a different jurisdiction
 *
 * A claimed exemption that cannot be supported is never silently treated as
 * exempt; it becomes review_required with machine-readable reasons.
 *
 * No HTTP, auth, TenantContext, Eloquent, database access, audits, or events.
 */
final class QuoteTaxCalculator
{
    public const RATE_PARTS_PER_MILLION = Money::RATE_PARTS_PER_MILLION;

    public const REASON_TAX_CALCULATION_DISABLED = 'tax_calculation_disabled';

    public const REASON_MISSING_JURISDICTION = 'missing_jurisdiction';

    public const REASON_AMBIGUOUS_JURISDICTION = 'ambiguous_jurisdiction';

    public const REASON_MISSING_CONFIGURED_RATE = 'missing_configured_rate';

    public function calculate(QuoteTaxCalculationInput $input): QuoteTaxCalculationResult
    {
        if ($input->taxableBasisCents < 0) {
            throw new InvalidQuoteTaxCalculationException('Taxable basis cannot be negative.');
        }

        if ($input->overrideTaxCents !== null) {
            return $this->manualOverride($input);
        }

        if ($input->exemptionClaimed) {
            return $this->exemptionPath($input);
        }

        return $this->configuredRatePath($input);
    }

    private function manualOverride(QuoteTaxCalculationInput $input): QuoteTaxCalculationResult
    {
        $tax = $input->overrideTaxCents;

        if ($tax === null || $tax < 0) {
            throw new InvalidQuoteTaxCalculationException('Manual tax override cannot be negative.');
        }

        if (trim((string) $input->overrideReason) === '') {
            throw new InvalidQuoteTaxCalculationException('Manual tax override requires a reason.');
        }

        return new QuoteTaxCalculationResult(
            outcome: QuoteTaxCalculationOutcome::Calculated,
            taxableBasisCents: $input->taxableBasisCents,
            taxCents: $tax,
            ratePpm: $input->ratePpm,
            source: QuoteTaxCalculationSource::ManualOverride,
            isOverride: true,
            overrideReason: $input->overrideReason,
            reviewReasons: [],
            jurisdictionSnapshot: $input->jurisdictionSnapshot,
            certificateId: $input->certificateApplicability?->certificateId,
            calculatorVersion: $input->calculatorVersion,
        );
    }

    private function exemptionPath(QuoteTaxCalculationInput $input): QuoteTaxCalculationResult
    {
        $applicability = $input->certificateApplicability;

        if ($applicability !== null && $applicability->isApplicable) {
            return new QuoteTaxCalculationResult(
                outcome: QuoteTaxCalculationOutcome::Exempt,
                taxableBasisCents: $input->taxableBasisCents,
                taxCents: 0,
                ratePpm: $input->ratePpm,
                source: QuoteTaxCalculationSource::VerifiedExemption,
                isOverride: false,
                overrideReason: null,
                reviewReasons: [],
                jurisdictionSnapshot: $input->jurisdictionSnapshot,
                certificateId: $applicability->certificateId,
                calculatorVersion: $input->calculatorVersion,
            );
        }

        $reasons = $applicability === null
            ? [OrganizationCompanyTaxCertificateApplicability::REASON_MISSING_CERTIFICATE]
            : $applicability->reasons;

        return $this->reviewRequired($input, $reasons, $applicability?->certificateId);
    }

    private function configuredRatePath(QuoteTaxCalculationInput $input): QuoteTaxCalculationResult
    {
        $reasons = [];

        if (! $input->taxCalculationEnabled) {
            $reasons[] = self::REASON_TAX_CALCULATION_DISABLED;
        }

        if ($input->jurisdictionAmbiguous) {
            $reasons[] = self::REASON_AMBIGUOUS_JURISDICTION;
        }

        if ($input->jurisdictionSnapshot === null || $input->jurisdictionSnapshot === []) {
            $reasons[] = self::REASON_MISSING_JURISDICTION;
        }

        if ($input->ratePpm === null) {
            $reasons[] = self::REASON_MISSING_CONFIGURED_RATE;
        }

        if ($reasons !== []) {
            return $this->reviewRequired($input, $reasons, null);
        }

        /** @var int $ratePpm */
        $ratePpm = $input->ratePpm;

        if ($ratePpm < 0) {
            throw new InvalidQuoteTaxCalculationException('Rate parts-per-million cannot be negative.');
        }

        return new QuoteTaxCalculationResult(
            outcome: QuoteTaxCalculationOutcome::Calculated,
            taxableBasisCents: $input->taxableBasisCents,
            taxCents: Money::applyRatePartsPerMillionToCents($input->taxableBasisCents, $ratePpm),
            ratePpm: $ratePpm,
            source: QuoteTaxCalculationSource::ConfiguredRate,
            isOverride: false,
            overrideReason: null,
            reviewReasons: [],
            jurisdictionSnapshot: $input->jurisdictionSnapshot,
            certificateId: null,
            calculatorVersion: $input->calculatorVersion,
        );
    }

    /**
     * @param  list<string>  $reasons
     */
    private function reviewRequired(
        QuoteTaxCalculationInput $input,
        array $reasons,
        ?int $certificateId,
    ): QuoteTaxCalculationResult {
        return new QuoteTaxCalculationResult(
            outcome: QuoteTaxCalculationOutcome::ReviewRequired,
            taxableBasisCents: $input->taxableBasisCents,
            taxCents: 0,
            ratePpm: $input->ratePpm,
            source: QuoteTaxCalculationSource::ConfiguredRate,
            isOverride: false,
            overrideReason: null,
            reviewReasons: array_values(array_unique($reasons)),
            jurisdictionSnapshot: $input->jurisdictionSnapshot,
            certificateId: $certificateId,
            calculatorVersion: $input->calculatorVersion,
        );
    }
}
