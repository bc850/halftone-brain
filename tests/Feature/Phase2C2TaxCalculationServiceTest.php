<?php

use App\Enums\QuoteTaxCalculationOutcome;
use App\Enums\QuoteTaxCalculationSource;
use App\Enums\QuoteTaxCalculationStatus;
use App\Models\AuditEvent;
use App\Models\QuoteApprovalRequest;
use App\Models\QuoteRevisionTaxCalculation;
use App\Support\Quotes\StaleQuoteStateException;
use App\Support\Quotes\Tax\InvalidQuoteTaxCalculationException;
use App\Support\Quotes\Tax\QuoteTaxCalculationService;
use App\Support\Tax\OrganizationCompanyTaxCertificateService;
use App\Support\Tax\OrganizationTaxRateManagementService;
use Tests\Support\Phase2C2Fixture;

test('a configured rate produces tax, a new history version, and one lock bump', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = Phase2C2Fixture::draftQuote();
    Phase2C2Fixture::taxProfile($ctx);
    $rate = Phase2C2Fixture::taxRate($ctx);

    $lockVersionBefore = $revision->lock_version;

    $calculation = app(QuoteTaxCalculationService::class)->calculate(
        quote: $quote,
        revision: $revision,
        expectedLockVersion: $lockVersionBefore,
        organizationTaxRateId: $rate->id,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    $revision = $revision->fresh();

    expect($calculation->outcome)->toBe(QuoteTaxCalculationOutcome::Calculated)
        ->and($calculation->calculation_version)->toBe(1)
        ->and($calculation->taxable_basis_cents)->toBe(100_000)
        ->and($calculation->tax_cents)->toBe(8_000)
        ->and($calculation->rate_ppm)->toBe(80_000)
        ->and($calculation->source)->toBe(QuoteTaxCalculationSource::ConfiguredRate)
        ->and($calculation->is_override)->toBeFalse()
        ->and($calculation->jurisdiction_snapshot_json['jurisdiction_code'])->toBe('us-ga-fulton')
        ->and($revision->tax_calculation_status)->toBe(QuoteTaxCalculationStatus::Calculated)
        ->and($revision->tax_cents)->toBe(8_000)
        ->and($revision->taxable_amount_cents)->toBe(100_000)
        ->and($revision->grand_total_cents)->toBe(108_000)
        ->and($revision->current_tax_calculation_id)->toBe($calculation->id)
        ->and($revision->tax_snapshot_json['rate_ppm'])->toBe(80_000)
        ->and($revision->lock_version)->toBe($lockVersionBefore + 1)
        ->and($revision->tax_calculated_at)->not->toBeNull();

    // Recalculating appends a version rather than editing the one that was relied on.
    $superseding = app(QuoteTaxCalculationService::class)->calculate(
        quote: $quote->fresh(),
        revision: $revision,
        expectedLockVersion: $revision->lock_version,
        organizationTaxRateId: $rate->id,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    expect($superseding->calculation_version)->toBe(2)
        ->and(QuoteRevisionTaxCalculation::query()->count())->toBe(2)
        ->and($revision->fresh()->current_tax_calculation_id)->toBe($superseding->id)
        ->and($revision->fresh()->grand_total_cents)->toBe(108_000);

    // Deciding tax never asks anyone for approval.
    expect(QuoteApprovalRequest::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'crm.quote.tax_calculated')->count())->toBe(2);
});

test('a verified in-jurisdiction certificate exempts the sale', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision, 'organizationCompany' => $company] =
        Phase2C2Fixture::draftQuote();
    Phase2C2Fixture::taxProfile($ctx);
    $rate = Phase2C2Fixture::taxRate($ctx);
    $certificate = Phase2C2Fixture::verifiedCertificate($ctx, $company);

    $calculation = app(QuoteTaxCalculationService::class)->calculate(
        quote: $quote,
        revision: $revision,
        expectedLockVersion: $revision->lock_version,
        organizationTaxRateId: $rate->id,
        certificateId: $certificate->id,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    $revision = $revision->fresh();

    expect($calculation->outcome)->toBe(QuoteTaxCalculationOutcome::Exempt)
        ->and($calculation->tax_cents)->toBe(0)
        ->and($calculation->source)->toBe(QuoteTaxCalculationSource::VerifiedExemption)
        ->and($calculation->organization_company_tax_certificate_id)->toBe($certificate->id)
        ->and($calculation->certificate_evidence_snapshot_json)->not->toHaveKey('certificate_number')
        ->and($revision->tax_calculation_status)->toBe(QuoteTaxCalculationStatus::Exempt)
        ->and($revision->grand_total_cents)->toBe(100_000)
        ->and(AuditEvent::query()->where('action', 'crm.quote.tax_exempt')->count())->toBe(1);
});

test('an unsupportable exemption claim becomes review required with reasons', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision, 'organizationCompany' => $company] =
        Phase2C2Fixture::draftQuote();
    Phase2C2Fixture::taxProfile($ctx);
    $rate = Phase2C2Fixture::taxRate($ctx);
    $unverified = Phase2C2Fixture::certificate($ctx, $company);

    $calculation = app(QuoteTaxCalculationService::class)->calculate(
        quote: $quote,
        revision: $revision,
        expectedLockVersion: $revision->lock_version,
        organizationTaxRateId: $rate->id,
        certificateId: $unverified->id,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    $revision = $revision->fresh();

    expect($calculation->outcome)->toBe(QuoteTaxCalculationOutcome::ReviewRequired)
        ->and($calculation->tax_cents)->toBe(0)
        ->and($revision->tax_calculation_status)->toBe(QuoteTaxCalculationStatus::ReviewRequired)
        ->and($revision->tax_snapshot_json['review_reasons'])->toContain('certificate_pending_verification')
        ->and($revision->grand_total_cents)->toBe(100_000)
        ->and(AuditEvent::query()->where('action', 'crm.quote.tax_review_required')->count())->toBe(1);

    // A certificate issued for another state cannot support this sale either.
    $wrongState = app(OrganizationCompanyTaxCertificateService::class)->verify(
        Phase2C2Fixture::certificate($ctx, $company, jurisdictionState: 'FL'),
        $ctx['membership'],
        $ctx['user'],
    );

    $mismatch = app(QuoteTaxCalculationService::class)->calculate(
        quote: $quote->fresh(),
        revision: $revision,
        expectedLockVersion: $revision->lock_version,
        organizationTaxRateId: $rate->id,
        certificateId: $wrongState->id,
        actor: $ctx['user'],
    );

    expect($mismatch->outcome)->toBe(QuoteTaxCalculationOutcome::ReviewRequired)
        ->and($revision->fresh()->tax_snapshot_json['review_reasons'])
        ->toContain('certificate_jurisdiction_mismatch');
});

test('a disabled tax configuration leaves the position unresolved instead of untaxed', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = Phase2C2Fixture::draftQuote();
    Phase2C2Fixture::taxProfile($ctx, ['tax_calculation_enabled' => false]);
    $rate = Phase2C2Fixture::taxRate($ctx);

    $calculation = app(QuoteTaxCalculationService::class)->calculate(
        quote: $quote,
        revision: $revision,
        expectedLockVersion: $revision->lock_version,
        organizationTaxRateId: $rate->id,
        actor: $ctx['user'],
    );

    expect($calculation->outcome)->toBe(QuoteTaxCalculationOutcome::ReviewRequired)
        ->and($revision->fresh()->tax_snapshot_json['review_reasons'])->toContain('tax_calculation_disabled');
});

test('a manual override records the amount, the reason, and its own history version', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = Phase2C2Fixture::draftQuote();
    Phase2C2Fixture::taxProfile($ctx);
    $service = app(QuoteTaxCalculationService::class);

    expect(fn () => $service->override(
        quote: $quote,
        revision: $revision,
        expectedLockVersion: $revision->lock_version,
        overrideTax: 1_234,
        reason: '   ',
    ))->toThrow(InvalidQuoteTaxCalculationException::class);

    $calculation = $service->override(
        quote: $quote,
        revision: $revision,
        expectedLockVersion: $revision->lock_version,
        overrideTax: '73.21',
        reason: 'Municipal surcharge no configured rate covers',
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    $revision = $revision->fresh();

    expect($calculation->is_override)->toBeTrue()
        ->and($calculation->source)->toBe(QuoteTaxCalculationSource::ManualOverride)
        ->and($calculation->tax_cents)->toBe(7_321)
        ->and($calculation->override_reason)->toBe('Municipal surcharge no configured rate covers')
        ->and($calculation->outcome)->toBe(QuoteTaxCalculationOutcome::Calculated)
        ->and($revision->tax_cents)->toBe(7_321)
        ->and($revision->tax_calculation_status)->toBe(QuoteTaxCalculationStatus::Calculated)
        ->and($revision->grand_total_cents)->toBe(107_321)
        ->and(AuditEvent::query()->where('action', 'crm.quote.tax_overridden')->count())->toBe(1);

    expect(fn () => $service->override(
        quote: $quote->fresh(),
        revision: $revision,
        expectedLockVersion: $revision->lock_version,
        overrideTax: -1,
        reason: 'Negative tax',
    ))->toThrow(InvalidQuoteTaxCalculationException::class);
});

test('tax calculation refuses stale state, foreign rates, and rates that are not in force', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = Phase2C2Fixture::draftQuote();
    Phase2C2Fixture::taxProfile($ctx);
    $rate = Phase2C2Fixture::taxRate($ctx);
    $service = app(QuoteTaxCalculationService::class);

    expect(fn () => $service->calculate(
        quote: $quote,
        revision: $revision,
        expectedLockVersion: $revision->lock_version - 1,
        organizationTaxRateId: $rate->id,
    ))->toThrow(StaleQuoteStateException::class);

    $otherCtx = createTenantUser('owner');
    $foreignRate = Phase2C2Fixture::taxRate($otherCtx);

    expect(fn () => $service->calculate(
        quote: $quote,
        revision: $revision,
        expectedLockVersion: $revision->lock_version,
        organizationTaxRateId: $foreignRate->id,
    ))->toThrow(InvalidQuoteTaxCalculationException::class);

    $retired = app(OrganizationTaxRateManagementService::class)->deactivate($rate, $ctx['user']);

    expect(fn () => $service->calculate(
        quote: $quote,
        revision: $revision,
        expectedLockVersion: $revision->lock_version,
        organizationTaxRateId: $retired->id,
    ))->toThrow(InvalidQuoteTaxCalculationException::class);

    $lapsed = Phase2C2Fixture::taxRate(
        $ctx,
        jurisdictionCode: 'us-ga-cobb',
        effectiveFrom: '2020-01-01',
        effectiveThrough: '2020-12-31',
    );

    expect(fn () => $service->calculate(
        quote: $quote,
        revision: $revision,
        expectedLockVersion: $revision->lock_version,
        organizationTaxRateId: $lapsed->id,
    ))->toThrow(InvalidQuoteTaxCalculationException::class);

    // No rate at all is a refusal, not a silent zero.
    expect(fn () => $service->calculate(
        quote: $quote,
        revision: $revision,
        expectedLockVersion: $revision->lock_version,
        organizationTaxRateId: 0,
    ))->toThrow(InvalidQuoteTaxCalculationException::class);

    expect($revision->fresh()->tax_calculation_status)->toBe(QuoteTaxCalculationStatus::Pending)
        ->and(QuoteRevisionTaxCalculation::query()->count())->toBe(0);
});
