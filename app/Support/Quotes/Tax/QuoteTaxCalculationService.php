<?php

namespace App\Support\Quotes\Tax;

use App\Enums\QuoteTaxCalculationOutcome;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationCompanyTaxCertificate;
use App\Models\OrganizationTaxProfile;
use App\Models\OrganizationTaxRate;
use App\Models\ParentAccount;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionPartySnapshot;
use App\Models\QuoteRevisionTaxCalculation;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Money;
use App\Support\Quotes\Approval\QuoteApprovalWorkflowService;
use App\Support\Quotes\QuoteDraftLock;
use App\Support\Quotes\QuoteRevisionTotalsSynchronizer;
use App\Support\Quotes\Snapshots\CustomerSafeTaxProjection;
use App\Support\Quotes\Totals\QuoteDiscountTaxAllocation;
use App\Support\Quotes\Totals\QuoteDiscountTaxAllocator;
use App\Support\Tax\OrganizationCompanyTaxCertificateApplicability;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Resolves the tax position of a draft revision and records it as history.
 *
 * Tax is decided only on a draft: a customer-visible revision is frozen, and the
 * numbers a tax figure describes cannot change underneath it. The caller supplies the
 * jurisdiction by picking a configured rate — the system never guesses one from an
 * address, so there is no ambiguous-jurisdiction outcome to explain here; picking the
 * rate *is* the confirmation.
 *
 * Every run appends a new {@see QuoteRevisionTaxCalculation} version and repoints the
 * revision at it, so an auditor can read the rate, jurisdiction, and evidence that were
 * used at the time even after a recalculation. Nothing is ever edited in place.
 *
 * An approval request is never created here. Whether the resolved total needs approval
 * is {@see QuoteApprovalWorkflowService}'s decision.
 *
 * Permission checks belong to the caller: `crm.quote.tax_calculate` for
 * {@see calculate()} and `crm.quote.tax_override` for {@see override()}.
 */
final class QuoteTaxCalculationService
{
    public const CALCULATOR_VERSION = 'quote-tax-calculator-2c2';

    public function __construct(
        private QuoteDraftLock $lock,
        private QuoteRevisionTotalsSynchronizer $totals,
        private QuoteDiscountTaxAllocator $allocator,
        private QuoteTaxCalculator $calculator,
        private OrganizationCompanyTaxCertificateApplicability $applicability,
        private Auditor $auditor,
    ) {}

    /**
     * Apply a configured rate, optionally against a claimed exemption certificate.
     *
     * A claimed exemption that the certificate cannot support does not become exempt; it
     * becomes `review_required` with machine-readable reasons.
     */
    public function calculate(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedLockVersion,
        int $organizationTaxRateId,
        ?int $certificateId = null,
        ?User $actor = null,
        ?Membership $actorMembership = null,
    ): QuoteRevisionTaxCalculation {
        return $this->resolve(
            quote: $quote,
            revision: $revision,
            expectedLockVersion: $expectedLockVersion,
            organizationTaxRateId: $organizationTaxRateId,
            certificateId: $certificateId,
            overrideTaxCents: null,
            overrideReason: null,
            actor: $actor,
            actorMembership: $actorMembership,
        );
    }

    /**
     * Record a manually determined tax amount.
     *
     * The override still goes through the calculator and still lands in history as its
     * own append-only version, carrying the reason it was needed. The rate is optional
     * because an override exists precisely for situations no configured rate covers.
     *
     * @param  int|string  $overrideTax  cents as an int, or a decimal dollar string such as "128.45"
     */
    public function override(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedLockVersion,
        int|string $overrideTax,
        string $reason,
        ?int $organizationTaxRateId = null,
        ?User $actor = null,
        ?Membership $actorMembership = null,
    ): QuoteRevisionTaxCalculation {
        if (trim($reason) === '') {
            throw new InvalidQuoteTaxCalculationException('Manual tax override requires a reason.');
        }

        return $this->resolve(
            quote: $quote,
            revision: $revision,
            expectedLockVersion: $expectedLockVersion,
            organizationTaxRateId: $organizationTaxRateId,
            certificateId: null,
            overrideTaxCents: $this->toCents($overrideTax),
            overrideReason: trim($reason),
            actor: $actor,
            actorMembership: $actorMembership,
        );
    }

    private function resolve(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedLockVersion,
        ?int $organizationTaxRateId,
        ?int $certificateId,
        ?int $overrideTaxCents,
        ?string $overrideReason,
        ?User $actor,
        ?Membership $actorMembership,
    ): QuoteRevisionTaxCalculation {
        return DB::transaction(function () use (
            $quote,
            $revision,
            $expectedLockVersion,
            $organizationTaxRateId,
            $certificateId,
            $overrideTaxCents,
            $overrideReason,
            $actor,
            $actorMembership,
        ): QuoteRevisionTaxCalculation {
            ['quote' => $lockedQuote, 'revision' => $lockedRevision] = $this->lock->lockDraft(
                $quote,
                $revision,
                $expectedLockVersion,
            );

            $snapshot = QuoteRevisionPartySnapshot::query()
                ->where('quote_revision_id', $lockedRevision->id)
                ->lockForUpdate()
                ->first();

            $calculationDate = $lockedRevision->issue_date ?? now()->startOfDay();
            $profile = OrganizationTaxProfile::query()
                ->where('organization_id', $lockedQuote->organization_id)
                ->first();

            $rate = $organizationTaxRateId === null
                ? null
                : $this->requireUsableRate($lockedQuote, $organizationTaxRateId, $calculationDate);

            if ($rate === null && $overrideTaxCents === null) {
                throw new InvalidQuoteTaxCalculationException(
                    'A configured jurisdiction rate must be selected before tax can be calculated.'
                );
            }

            $certificate = $certificateId === null
                ? null
                : $this->requireCertificate($lockedQuote, $certificateId);

            // Totals are resynchronized first so the taxable basis is derived from the
            // line and adjustment rows as they stand right now, not from a stale header.
            $totals = $this->totals->sync($lockedRevision, $actor);
            $allocation = $this->allocator->allocateFromTotals($totals);

            $applicability = $certificate === null
                ? null
                : $this->applicability->evaluate(
                    $certificate,
                    $this->resolveJurisdictionState($rate, $profile, $snapshot),
                    $calculationDate,
                );

            $result = $this->calculator->calculate(new QuoteTaxCalculationInput(
                taxableBasisCents: $allocation->taxableBasisCents,
                calculatorVersion: self::CALCULATOR_VERSION,
                ratePpm: $rate?->rate_ppm,
                jurisdictionSnapshot: $rate?->toJurisdictionSnapshot(),
                exemptionClaimed: $certificate !== null,
                certificateApplicability: $applicability,
                taxCalculationEnabled: $profile !== null
                    && $profile->is_active
                    && $profile->tax_calculation_enabled,
                overrideTaxCents: $overrideTaxCents,
                overrideReason: $overrideReason,
            ));

            $correlationId = (string) Str::uuid();

            $calculation = QuoteRevisionTaxCalculation::query()->create([
                'parent_account_id' => $lockedRevision->parent_account_id,
                'organization_id' => $lockedRevision->organization_id,
                'quote_id' => $lockedQuote->id,
                'quote_revision_id' => $lockedRevision->id,
                'calculation_version' => $this->nextCalculationVersion($lockedRevision),
                'outcome' => $result->outcome,
                'taxable_basis_cents' => $result->taxableBasisCents,
                'rate_ppm' => $result->ratePpm,
                'tax_cents' => $result->taxCents,
                'jurisdiction_snapshot_json' => $result->jurisdictionSnapshot,
                'organization_company_tax_certificate_id' => $certificate?->id,
                'certificate_evidence_snapshot_json' => $certificate?->toEvidenceSnapshot(),
                'source' => $result->source,
                'is_override' => $result->isOverride,
                'override_reason' => $result->overrideReason,
                'actor_membership_id' => $actorMembership?->id,
                'actor_user_id' => $actor?->id,
                'calculator_version' => $result->calculatorVersion,
                'calculated_at' => now(),
                'correlation_id' => $correlationId,
            ]);

            $before = [
                'tax_calculation_status' => $lockedRevision->tax_calculation_status->value,
                'tax_cents' => $lockedRevision->tax_cents,
                'grand_total_cents' => $lockedRevision->grand_total_cents,
                'current_tax_calculation_id' => $lockedRevision->current_tax_calculation_id,
            ];

            $lockedRevision->forceFill([
                'taxable_amount_cents' => $result->taxableBasisCents,
                'tax_cents' => $result->taxCents,
                'tax_calculation_status' => $result->revisionStatus(),
                'tax_snapshot_json' => $this->taxSnapshot($result, $allocation, $calculation),
                'tax_calculated_at' => $calculation->calculated_at,
                'current_tax_calculation_id' => $calculation->id,
                'grand_total_cents' => $totals->finalPretaxAmountCents + $result->taxCents,
            ])->save();

            $this->lock->bumpRevisionLock($lockedRevision);

            $this->auditor->append(
                parentAccount: ParentAccount::query()->findOrFail($lockedQuote->parent_account_id),
                action: $this->auditAction($result),
                subjectType: QuoteRevision::class,
                subjectId: $lockedRevision->id,
                organization: Organization::query()->findOrFail($lockedQuote->organization_id),
                actor: $actor,
                before: $before,
                after: [
                    'tax_calculation_status' => $result->revisionStatus()->value,
                    'tax_cents' => $result->taxCents,
                    'taxable_basis_cents' => $result->taxableBasisCents,
                    'grand_total_cents' => $lockedRevision->grand_total_cents,
                    'current_tax_calculation_id' => $calculation->id,
                    'calculation_version' => $calculation->calculation_version,
                    'source' => $result->source->value,
                    'is_override' => $result->isOverride,
                    'review_reasons' => $result->reviewReasons,
                    'lock_version' => $lockedRevision->lock_version,
                ],
                correlationId: $correlationId,
            );

            return $calculation;
        });
    }

    private function requireUsableRate(
        Quote $quote,
        int $organizationTaxRateId,
        CarbonInterface $calculationDate,
    ): OrganizationTaxRate {
        $rate = OrganizationTaxRate::query()
            ->whereKey($organizationTaxRateId)
            ->lockForUpdate()
            ->first();

        if ($rate === null || $rate->organization_id !== $quote->organization_id) {
            throw new InvalidQuoteTaxCalculationException(
                'The selected tax rate does not belong to this organization.'
            );
        }

        if (! $rate->is_active) {
            throw new InvalidQuoteTaxCalculationException(
                "Tax rate [{$rate->id}] is no longer active and cannot be applied."
            );
        }

        $date = $calculationDate->toDateString();

        if ($rate->effective_from->toDateString() > $date
            || ($rate->effective_through !== null && $rate->effective_through->toDateString() < $date)) {
            throw new InvalidQuoteTaxCalculationException(sprintf(
                'Tax rate [%d] is not effective on %s.',
                $rate->id,
                $date,
            ));
        }

        return $rate;
    }

    private function requireCertificate(Quote $quote, int $certificateId): OrganizationCompanyTaxCertificate
    {
        $certificate = OrganizationCompanyTaxCertificate::query()
            ->whereKey($certificateId)
            ->lockForUpdate()
            ->first();

        if ($certificate === null
            || $certificate->organization_id !== $quote->organization_id
            || $certificate->organization_company_id !== $quote->organization_company_id) {
            throw new InvalidQuoteTaxCalculationException(
                'The selected exemption certificate does not belong to this quote customer.'
            );
        }

        return $certificate;
    }

    /**
     * The state being taxed: the selected rate names it, the tax profile default covers
     * a rate configured without one, and the service or billing address is the last
     * resort. A certificate issued for a different state can never support the sale.
     */
    private function resolveJurisdictionState(
        ?OrganizationTaxRate $rate,
        ?OrganizationTaxProfile $profile,
        ?QuoteRevisionPartySnapshot $snapshot,
    ): string {
        $candidates = [
            $rate?->state,
            $profile?->default_state,
            $this->addressState($snapshot?->service_address_json),
            $this->addressState($snapshot?->billing_address_json),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return strtoupper(trim($candidate));
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>|null  $address
     */
    private function addressState(?array $address): ?string
    {
        $state = $address['state'] ?? null;

        return is_string($state) ? $state : null;
    }

    private function nextCalculationVersion(QuoteRevision $revision): int
    {
        return (int) QuoteRevisionTaxCalculation::query()
            ->where('quote_revision_id', $revision->id)
            ->max('calculation_version') + 1;
    }

    /**
     * Internal snapshot of how the figure was reached. Customer-facing payloads are
     * projected from this by {@see CustomerSafeTaxProjection},
     * which drops the review reasons, override reason, and evidence.
     *
     * @return array<string, mixed>
     */
    private function taxSnapshot(
        QuoteTaxCalculationResult $result,
        QuoteDiscountTaxAllocation $allocation,
        QuoteRevisionTaxCalculation $calculation,
    ): array {
        return [
            'calculation_id' => $calculation->id,
            'calculation_version' => $calculation->calculation_version,
            'outcome' => $result->outcome->value,
            'source' => $result->source->value,
            'taxable_basis_cents' => $result->taxableBasisCents,
            'tax_cents' => $result->taxCents,
            'rate_ppm' => $result->ratePpm,
            'jurisdiction' => $result->jurisdictionSnapshot,
            'review_reasons' => $result->reviewReasons,
            'is_override' => $result->isOverride,
            'certificate_id' => $result->certificateId,
            'taxable_line_net_cents' => $allocation->taxableLineNetCents,
            'nontaxable_line_net_cents' => $allocation->nontaxableLineNetCents,
            'taxable_discount_allocation_cents' => $allocation->taxableDiscountAllocationCents,
            'taxable_positive_adjustment_cents' => $allocation->taxablePositiveAdjustmentCents,
            'calculator_version' => $result->calculatorVersion,
            'calculated_at' => $calculation->calculated_at->toIso8601String(),
        ];
    }

    private function auditAction(QuoteTaxCalculationResult $result): string
    {
        if ($result->isOverride) {
            return 'crm.quote.tax_overridden';
        }

        return match ($result->outcome) {
            QuoteTaxCalculationOutcome::Calculated => 'crm.quote.tax_calculated',
            QuoteTaxCalculationOutcome::Exempt => 'crm.quote.tax_exempt',
            QuoteTaxCalculationOutcome::ReviewRequired => 'crm.quote.tax_review_required',
        };
    }

    private function toCents(int|string $overrideTax): int
    {
        if (is_int($overrideTax)) {
            if ($overrideTax < 0) {
                throw new InvalidQuoteTaxCalculationException('Manual tax override cannot be negative.');
            }

            return $overrideTax;
        }

        try {
            return Money::dollarsToCents(trim($overrideTax));
        } catch (InvalidArgumentException $exception) {
            throw new InvalidQuoteTaxCalculationException(
                'Manual tax override must be a non-negative decimal amount.',
                0,
                $exception,
            );
        }
    }
}
