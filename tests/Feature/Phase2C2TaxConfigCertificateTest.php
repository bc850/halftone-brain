<?php

use App\Enums\TaxCertificateVerificationStatus;
use App\Enums\TaxExemptionCategory;
use App\Enums\TaxSourcingStrategy;
use App\Models\AuditEvent;
use App\Models\OrganizationCompanyTaxCertificate;
use App\Models\OrganizationTaxRate;
use App\Support\Tax\InvalidTaxConfigurationException;
use App\Support\Tax\OrganizationCompanyTaxCertificateService;
use App\Support\Tax\OrganizationTaxProfileService;
use App\Support\Tax\OrganizationTaxRateManagementService;
use App\Support\Tax\OverlappingTaxRateException;
use Tests\Support\Phase2C2Fixture;

test('a tax profile bumps its configuration version only when the decision inputs change', function () {
    $ctx = createTenantUser('owner');
    $profiles = app(OrganizationTaxProfileService::class);

    $profile = $profiles->create(
        organization: $ctx['organization'],
        defaultState: 'ga',
        actor: $ctx['user'],
    );

    expect($profile->default_state)->toBe('GA')
        ->and($profile->default_country)->toBe('US')
        ->and($profile->configuration_version)->toBe(1)
        ->and($profile->tax_calculation_enabled)->toBeTrue();

    $renamed = $profiles->update($profile, ['registration_reference' => 'GA-STE-0099'], $ctx['user']);
    expect($renamed->configuration_version)->toBe(1);

    $resourced = $profiles->update(
        $renamed,
        ['sourcing_strategy' => TaxSourcingStrategy::Origin],
        $ctx['user'],
    );
    expect($resourced->configuration_version)->toBe(2);

    $disabled = $profiles->setTaxCalculationEnabled($resourced, false, $ctx['user']);
    expect($disabled->tax_calculation_enabled)->toBeFalse()
        ->and($disabled->configuration_version)->toBe(3);

    expect($profiles->setActive($disabled, false, $ctx['user'])->is_active)->toBeFalse();

    expect(fn () => $profiles->create(organization: $ctx['organization']))
        ->toThrow(InvalidTaxConfigurationException::class);

    expect(fn () => $profiles->update($profile->fresh(), ['rate_ppm' => 1]))
        ->toThrow(InvalidTaxConfigurationException::class);

    expect(AuditEvent::query()->where('action', 'crm.tax_profile.created')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'crm.tax_profile.updated')->count())->toBe(4);
});

test('rate percentages become parts per million and history is superseded rather than edited', function () {
    $ctx = createTenantUser('owner');
    $rates = app(OrganizationTaxRateManagementService::class);

    $rate = Phase2C2Fixture::taxRate($ctx, '8.5');

    expect($rate->rate_ppm)->toBe(85_000)
        ->and($rate->state)->toBe('GA')
        ->and($rate->is_active)->toBeTrue()
        ->and($rate->effective_through)->toBeNull();

    expect(fn () => $rates->create(
        organization: $ctx['organization'],
        jurisdictionCode: 'us-ga-fulton',
        displayName: 'Fulton County duplicate',
        ratePercent: '9',
        effectiveFrom: '2024-01-01',
    ))->toThrow(OverlappingTaxRateException::class);

    expect(fn () => $rates->update($rate, ['rate_ppm' => 90_000]))
        ->toThrow(InvalidTaxConfigurationException::class);

    $relabelled = $rates->update(
        $rate,
        ['display_name' => 'Fulton County (Atlanta)', 'source_note' => 'DOR bulletin 2026-03'],
        $ctx['user'],
    );
    expect($relabelled->display_name)->toBe('Fulton County (Atlanta)')
        ->and($relabelled->rate_ppm)->toBe(85_000);

    $replacement = $rates->supersede($relabelled, '8.9', '2026-04-01', 'Rate increase', $ctx['user']);

    expect($replacement->rate_ppm)->toBe(89_000)
        ->and($replacement->effective_from->toDateString())->toBe('2026-04-01')
        ->and($replacement->id)->not->toBe($rate->id)
        ->and($rate->fresh()->effective_through->toDateString())->toBe('2026-03-31')
        ->and($rate->fresh()->rate_ppm)->toBe(85_000);

    expect(fn () => $rates->supersede($replacement, '9', '2026-01-01'))
        ->toThrow(InvalidTaxConfigurationException::class);

    $deactivated = $rates->deactivate($replacement, $ctx['user']);
    expect($deactivated->is_active)->toBeFalse()
        ->and(OrganizationTaxRate::query()->count())->toBe(2)
        ->and($rates->activate($deactivated, $ctx['user'])->is_active)->toBeTrue();

    expect(AuditEvent::query()->where('action', 'crm.tax_rate.superseded')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'crm.tax_rate.deactivated')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'crm.tax_rate.activated')->count())->toBe(1);
});

test('certificates move through verification without ever being deleted', function () {
    ['ctx' => $ctx, 'organizationCompany' => $company] = Phase2C2Fixture::draftQuote();
    $certificates = app(OrganizationCompanyTaxCertificateService::class);

    $unsupported = $certificates->create(
        organizationCompany: $company,
        exemptionCategory: TaxExemptionCategory::QualifyingNonprofit,
        jurisdictionState: 'ga',
        certificateFormType: 'ST-5',
        effectiveDate: '2026-01-01',
        certificateNumber: 'CERT-SECRET-1234',
        evidenceReference: null,
        actor: $ctx['user'],
    );

    expect($unsupported->verification_status)->toBe(TaxCertificateVerificationStatus::Pending)
        ->and($unsupported->jurisdiction_state)->toBe('GA');

    // A category is a claim, never a conclusion: without stored evidence there is
    // nothing to have verified.
    expect(fn () => $certificates->verify($unsupported, $ctx['membership'], $ctx['user']))
        ->toThrow(InvalidTaxConfigurationException::class);

    $withEvidence = $certificates->update(
        $unsupported,
        ['evidence_reference' => 'files/exemption/ga-1234.pdf'],
        $ctx['user'],
    );

    $verified = $certificates->verify($withEvidence, $ctx['membership'], $ctx['user']);

    expect($verified->verification_status)->toBe(TaxCertificateVerificationStatus::Verified)
        ->and($verified->verified_by_membership_id)->toBe($ctx['membership']->id)
        ->and($verified->verified_at)->not->toBeNull();

    // Re-verifying is a no-op, not a second decision.
    expect($certificates->verify($verified, $ctx['membership'], $ctx['user'])->verified_at->toIso8601String())
        ->toBe($verified->verified_at->toIso8601String());

    expect(fn () => $certificates->update($verified, ['certificate_form_type' => 'ST-6']))
        ->toThrow(InvalidTaxConfigurationException::class);

    expect(fn () => $certificates->revoke($verified, $ctx['membership'], '  '))
        ->toThrow(InvalidTaxConfigurationException::class);

    $revoked = $certificates->revoke($verified, $ctx['membership'], 'Customer lost reseller status', $ctx['user']);
    expect($revoked->verification_status)->toBe(TaxCertificateVerificationStatus::Revoked)
        ->and(OrganizationCompanyTaxCertificate::query()->count())->toBe(1);

    $rejectable = Phase2C2Fixture::certificate($ctx, $company);
    expect(fn () => $certificates->reject($rejectable, $ctx['membership'], ''))
        ->toThrow(InvalidTaxConfigurationException::class);

    $rejected = $certificates->reject($rejectable, $ctx['membership'], 'Form does not match the state', $ctx['user']);
    expect($rejected->verification_status)->toBe(TaxCertificateVerificationStatus::Rejected)
        ->and($rejected->rejection_reason)->toBe('Form does not match the state');

    $lapsing = Phase2C2Fixture::certificate($ctx, $company, expirationDate: now()->addYear()->toDateString());
    expect(fn () => $certificates->markExpired($lapsing))
        ->toThrow(InvalidTaxConfigurationException::class);

    $lapsed = $certificates->update($lapsing, ['expiration_date' => now()->subDay()->toDateString()], $ctx['user']);
    expect($certificates->markExpired($lapsed, $ctx['user'])->verification_status)
        ->toBe(TaxCertificateVerificationStatus::Expired);

    expect(fn () => $certificates->create(
        organizationCompany: $company,
        exemptionCategory: TaxExemptionCategory::Resale,
        jurisdictionState: 'GA',
        certificateFormType: 'ST-5',
        effectiveDate: '2026-06-01',
        expirationDate: '2026-05-01',
    ))->toThrow(InvalidTaxConfigurationException::class);
});

test('certificate audits never carry the certificate number', function () {
    ['ctx' => $ctx, 'organizationCompany' => $company] = Phase2C2Fixture::draftQuote();

    Phase2C2Fixture::verifiedCertificate($ctx, $company);

    $audits = AuditEvent::query()
        ->where('subject_type', OrganizationCompanyTaxCertificate::class)
        ->get();

    expect($audits)->toHaveCount(2);

    foreach ($audits as $audit) {
        $encoded = json_encode([$audit->before_json, $audit->after_json]);

        expect($encoded)->not->toContain('CERT-SECRET-9911')
            ->and($audit->after_json)->toHaveKey('evidence_reference_present')
            ->and($audit->after_json)->not->toHaveKey('certificate_number')
            ->and($audit->after_json)->not->toHaveKey('internal_notes');
    }
});
