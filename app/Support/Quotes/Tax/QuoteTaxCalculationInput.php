<?php

namespace App\Support\Quotes\Tax;

use App\Support\Tax\TaxCertificateApplicability;

/**
 * Everything QuoteTaxCalculator needs, resolved by the caller.
 *
 * The calculator never looks anything up, so the caller supplies the basis, the
 * configured rate, the jurisdiction it resolved, and the certificate facts it
 * already evaluated.
 *
 * @property-read array<string, mixed>|null $jurisdictionSnapshot
 */
final readonly class QuoteTaxCalculationInput
{
    /**
     * @param  int  $taxableBasisCents  basis produced by QuoteDiscountTaxAllocator
     * @param  int|null  $ratePpm  configured rate in parts per million, null when none applies
     * @param  array<string, mixed>|null  $jurisdictionSnapshot  jurisdiction the caller resolved
     * @param  bool  $exemptionClaimed  whether the sale is being treated as exempt
     * @param  TaxCertificateApplicability|null  $certificateApplicability  already-evaluated certificate facts
     * @param  bool  $jurisdictionAmbiguous  caller could not resolve a single jurisdiction
     * @param  bool  $taxCalculationEnabled  organization tax profile switch
     * @param  int|null  $overrideTaxCents  manually determined tax, bypassing the rate
     * @param  string|null  $overrideReason  required whenever overrideTaxCents is set
     */
    public function __construct(
        public int $taxableBasisCents,
        public string $calculatorVersion,
        public ?int $ratePpm = null,
        public ?array $jurisdictionSnapshot = null,
        public bool $exemptionClaimed = false,
        public ?TaxCertificateApplicability $certificateApplicability = null,
        public bool $jurisdictionAmbiguous = false,
        public bool $taxCalculationEnabled = true,
        public ?int $overrideTaxCents = null,
        public ?string $overrideReason = null,
    ) {}
}
