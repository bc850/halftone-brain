<?php

use App\Enums\MembershipStatus;
use App\Enums\QuoteApprovalRequestStatus;
use App\Enums\QuoteLifecycleStatus;
use App\Enums\QuoteRevisionStatus;
use App\Enums\QuoteTaxCalculationStatus;
use App\Enums\TaxCertificateVerificationStatus;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Membership;
use App\Models\OrganizationCompanyTaxCertificate;
use App\Models\OrganizationTaxProfile;
use App\Models\OrganizationTaxRate;
use App\Models\QuoteApprovalRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\RoleAssigner;
use Tests\Support\Phase2C2Fixture;

function phase2c2HttpCompany(int $companyId): Company
{
    return Company::query()->findOrFail($companyId);
}

/**
 * A second user inside the same organization, on a different role, so authority can
 * be tested without also changing which tenant the record belongs to.
 *
 * @param  array<string, mixed>  $ctx
 */
function phase2c2HttpColleague(array $ctx, string $roleKey): User
{
    $user = User::factory()->create();

    $membership = Membership::factory()->create([
        'organization_id' => $ctx['organization']->id,
        'user_id' => $user->id,
        'status' => MembershipStatus::Active,
    ]);

    app(RoleAssigner::class)->assignToOrganizationMembership(
        $membership,
        Role::query()->where('key', $roleKey)->firstOrFail(),
    );

    return $user;
}

/**
 * @param  array<string, mixed>  $ctx
 */
function phase2c2HttpRevisionRoute(string $name, array $ctx, mixed $quote, mixed $revision): string
{
    return route($name, [$ctx['organization'], $quote, $revision]);
}

/**
 * Resolve tax on the fixture quote so approval steps have something to gate on.
 *
 * @param  array<string, mixed>  $ctx
 */
function phase2c2HttpResolveTax(array $ctx, mixed $quote, mixed $revision): void
{
    Phase2C2Fixture::taxProfile($ctx);
    $rate = Phase2C2Fixture::taxRate($ctx);

    test()->actingAs($ctx['user'])
        ->post(phase2c2HttpRevisionRoute('org.quotes.revisions.tax.calculate', $ctx, $quote, $revision->fresh()), [
            'expected_lock_version' => $revision->fresh()->lock_version,
            'organization_tax_rate_id' => $rate->id,
        ])
        ->assertRedirect();
}

test('phase 2c2 http tax settings page renders and the profile can be saved', function () {
    $ctx = createTenantUser('admin');

    $this->actingAs($ctx['user'])
        ->get(route('org.tax-settings.edit', $ctx['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('tax/Settings')
            ->where('profile', null)
            ->where('canManage', true)
            ->has('rates', 0)
            ->where('disclaimer', 'Rates must be verified against the applicable tax authority. '
                .'Halftone Brain stores configured rates and quote snapshots; it does not provide tax advice.'));

    $this->actingAs($ctx['user'])
        ->put(route('org.tax-settings.profile', $ctx['organization']), [
            'default_country' => 'US',
            'default_state' => 'GA',
            'sourcing_strategy' => 'delivery',
            'registration_reference' => 'GA-9911',
            'tax_calculation_enabled' => true,
            'is_active' => true,
        ])
        ->assertRedirect(route('org.tax-settings.edit', $ctx['organization']));

    $profile = OrganizationTaxProfile::query()->where('organization_id', $ctx['organization']->id)->sole();

    expect($profile->default_state)->toBe('GA')
        ->and($profile->registration_reference)->toBe('GA-9911');

    $this->actingAs($ctx['user'])
        ->post(route('org.tax-settings.rates.store', $ctx['organization']), [
            'jurisdiction_code' => 'us-ga-fulton',
            'display_name' => 'Fulton County, GA',
            'rate_percent' => '8',
            'country' => 'US',
            'state' => 'GA',
            'effective_from' => '2024-01-01',
            'source_note' => 'Entered for testing.',
        ])
        ->assertRedirect();

    $rate = OrganizationTaxRate::query()->where('organization_id', $ctx['organization']->id)->sole();

    expect($rate->rate_ppm)->toBe(80_000);

    $this->actingAs($ctx['user'])
        ->get(route('org.tax-settings.edit', $ctx['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rates.0.rate_percent', '8.0000')
            ->where('rates.0.jurisdiction_code', 'us-ga-fulton'));
});

test('phase 2c2 http a rate overlapping an existing one is refused', function () {
    $ctx = createTenantUser('admin');
    Phase2C2Fixture::taxRate($ctx, effectiveFrom: '2024-01-01');

    $this->actingAs($ctx['user'])
        ->postJson(route('org.tax-settings.rates.store', $ctx['organization']), [
            'jurisdiction_code' => 'us-ga-fulton',
            'display_name' => 'Fulton County, GA (duplicate)',
            'rate_percent' => '9',
            'country' => 'US',
            'state' => 'GA',
            'effective_from' => '2025-01-01',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('rate');

    expect(OrganizationTaxRate::query()->where('organization_id', $ctx['organization']->id)->count())->toBe(1);
});

test('phase 2c2 http a rate is superseded rather than rewritten', function () {
    $ctx = createTenantUser('admin');
    $rate = Phase2C2Fixture::taxRate($ctx, effectiveFrom: '2024-01-01');

    $this->actingAs($ctx['user'])
        ->post(route('org.tax-settings.rates.supersede', [$ctx['organization'], $rate]), [
            'rate_percent' => '8.5',
            'effective_from' => '2026-01-01',
            'source_note' => 'Rate change.',
        ])
        ->assertRedirect();

    $rates = OrganizationTaxRate::query()
        ->where('organization_id', $ctx['organization']->id)
        ->orderBy('effective_from')
        ->get();

    expect($rates)->toHaveCount(2)
        ->and($rates[0]->rate_ppm)->toBe(80_000)
        ->and($rates[0]->effective_through->toDateString())->toBe('2025-12-31')
        ->and($rates[1]->rate_ppm)->toBe(85_000);
});

test('phase 2c2 http tax settings are closed to a role without tax authority', function () {
    $ctx = createTenantUser('project_manager');

    $this->actingAs($ctx['user'])
        ->get(route('org.tax-settings.edit', $ctx['organization']))
        ->assertForbidden();

    $this->actingAs($ctx['user'])
        ->put(route('org.tax-settings.profile', $ctx['organization']), [
            'default_country' => 'US',
            'sourcing_strategy' => 'delivery',
            'tax_calculation_enabled' => true,
            'is_active' => true,
        ])
        ->assertForbidden();
});

test('phase 2c2 http a certificate moves through its lifecycle without being deleted', function () {
    ['ctx' => $ctx, 'organizationCompany' => $organizationCompany] = Phase2C2Fixture::draftQuote('admin');
    $company = phase2c2HttpCompany($organizationCompany->company_id);

    $this->actingAs($ctx['user'])
        ->post(route('org.companies.tax-certificates.store', [$ctx['organization'], $company]), [
            'exemption_category' => 'resale',
            'jurisdiction_state' => 'GA',
            'certificate_form_type' => 'ST-5',
            'certificate_number' => 'CERT-SECRET-9911',
            'evidence_reference' => 'files/exemption/ga-1234.pdf',
            'effective_date' => '2024-01-01',
        ])
        ->assertRedirect(route('org.companies.tax-certificates.index', [$ctx['organization'], $company]));

    $certificate = OrganizationCompanyTaxCertificate::query()->sole();

    expect($certificate->verification_status)->toBe(TaxCertificateVerificationStatus::Pending);

    $this->actingAs($ctx['user'])
        ->patch(route('org.companies.tax-certificates.update', [$ctx['organization'], $company, $certificate]), [
            'certificate_form_type' => 'ST-5 (revised)',
        ])
        ->assertRedirect();

    expect($certificate->fresh()->certificate_form_type)->toBe('ST-5 (revised)');

    $this->actingAs($ctx['user'])
        ->post(route('org.companies.tax-certificates.verify', [$ctx['organization'], $company, $certificate]))
        ->assertRedirect();

    expect($certificate->fresh()->verification_status)->toBe(TaxCertificateVerificationStatus::Verified);

    // A verified certificate is frozen: editing it is refused rather than silently applied.
    $this->actingAs($ctx['user'])
        ->patchJson(route('org.companies.tax-certificates.update', [$ctx['organization'], $company, $certificate]), [
            'certificate_form_type' => 'ST-5 (again)',
        ])
        ->assertStatus(422);

    $this->actingAs($ctx['user'])
        ->post(route('org.companies.tax-certificates.revoke', [$ctx['organization'], $company, $certificate]), [
            'reason' => 'Customer lost exempt status.',
        ])
        ->assertRedirect();

    expect($certificate->fresh()->verification_status)->toBe(TaxCertificateVerificationStatus::Revoked)
        ->and(OrganizationCompanyTaxCertificate::query()->count())->toBe(1);
});

test('phase 2c2 http rejecting a certificate demands a reason', function () {
    ['ctx' => $ctx, 'organizationCompany' => $organizationCompany] = Phase2C2Fixture::draftQuote('admin');
    $company = phase2c2HttpCompany($organizationCompany->company_id);
    $certificate = Phase2C2Fixture::certificate($ctx, $organizationCompany);

    $this->actingAs($ctx['user'])
        ->postJson(route('org.companies.tax-certificates.reject', [$ctx['organization'], $company, $certificate]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    $this->actingAs($ctx['user'])
        ->post(route('org.companies.tax-certificates.reject', [$ctx['organization'], $company, $certificate]), [
            'reason' => 'Form is for the wrong state.',
        ])
        ->assertRedirect();

    expect($certificate->fresh()->verification_status)->toBe(TaxCertificateVerificationStatus::Rejected);
});

test('phase 2c2 http certificate evidence is redacted where the reader has no certificate authority', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision, 'organizationCompany' => $organizationCompany]
        = Phase2C2Fixture::draftQuote('admin');
    $company = phase2c2HttpCompany($organizationCompany->company_id);
    Phase2C2Fixture::verifiedCertificate($ctx, $organizationCompany);

    // The certificates page is reached with certificate authority, so evidence is present.
    $this->actingAs($ctx['user'])
        ->get(route('org.companies.tax-certificates.index', [$ctx['organization'], $company]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('companies/TaxCertificates')
            ->where('canViewEvidence', true)
            ->where('certificates.0.certificate_number', 'CERT-SECRET-9911')
            ->where('certificates.0.has_evidence', true));

    // The builder only needs to know a certificate exists, so the keys are absent
    // there entirely rather than present and null.
    $this->actingAs($ctx['user'])
        ->get(phase2c2HttpRevisionRoute('org.quotes.revisions.edit', $ctx, $quote, $revision))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('quotes/Builder')
            ->has('tax.certificates', 1)
            ->where('tax.certificates.0.certificate_reference', fn (string $reference): bool => str_starts_with($reference, 'certificate:'))
            ->missing('tax.certificates.0.certificate_number')
            ->missing('tax.certificates.0.evidence_reference')
            ->missing('tax.certificates.0.internal_notes')
            ->missing('tax.certificates.0.rejection_reason'));
});

test('phase 2c2 http certificates are closed to a role with no certificate authority', function () {
    ['ctx' => $ctx, 'organizationCompany' => $organizationCompany] = Phase2C2Fixture::draftQuote('admin');
    $company = phase2c2HttpCompany($organizationCompany->company_id);

    $projectManager = phase2c2HttpColleague($ctx, 'project_manager');

    // Same organization, same company, narrower role: the page is refused outright
    // rather than served with the evidence stripped out.
    $this->actingAs($projectManager)
        ->get(route('org.companies.tax-certificates.index', [$ctx['organization'], $company]))
        ->assertForbidden();

    $this->actingAs($projectManager)
        ->post(route('org.companies.tax-certificates.store', [$ctx['organization'], $company]), [
            'exemption_category' => 'resale',
            'jurisdiction_state' => 'GA',
            'certificate_form_type' => 'ST-5',
            'effective_date' => '2024-01-01',
        ])
        ->assertForbidden();

    expect(OrganizationCompanyTaxCertificate::query()->count())->toBe(0);
});

test('phase 2c2 http calculating tax resolves a taxable position and lands in history', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = Phase2C2Fixture::draftQuote('admin');
    Phase2C2Fixture::taxProfile($ctx);
    $rate = Phase2C2Fixture::taxRate($ctx);

    $this->actingAs($ctx['user'])
        ->post(phase2c2HttpRevisionRoute('org.quotes.revisions.tax.calculate', $ctx, $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'organization_tax_rate_id' => $rate->id,
        ])
        ->assertRedirect();

    $revision = $revision->fresh();

    expect($revision->tax_calculation_status)->toBe(QuoteTaxCalculationStatus::Calculated)
        ->and($revision->tax_cents)->toBe(8_000)
        ->and($revision->grand_total_cents)->toBe(108_000);

    $this->actingAs($ctx['user'])
        ->get(phase2c2HttpRevisionRoute('org.quotes.revisions.edit', $ctx, $quote, $revision))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tax.status', 'calculated')
            ->where('tax.current.tax', '80.00')
            ->where('tax.current.rate_percent', '8.0000')
            ->has('tax.history', 1)
            ->where('revision.grand_total', '1080.00')
            ->where('revision.pretax_total', '1000.00'));

    $this->actingAs($ctx['user'])
        ->getJson(phase2c2HttpRevisionRoute('org.quotes.revisions.tax.history', $ctx, $quote, $revision))
        ->assertOk()
        ->assertJsonPath('history.0.calculation_version', 1);
});

test('phase 2c2 http a verified certificate resolves the quote as exempt', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision, 'organizationCompany' => $organizationCompany]
        = Phase2C2Fixture::draftQuote('admin');
    Phase2C2Fixture::taxProfile($ctx);
    $rate = Phase2C2Fixture::taxRate($ctx);
    $certificate = Phase2C2Fixture::verifiedCertificate($ctx, $organizationCompany);

    $this->actingAs($ctx['user'])
        ->post(phase2c2HttpRevisionRoute('org.quotes.revisions.tax.calculate', $ctx, $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'organization_tax_rate_id' => $rate->id,
            'certificate_id' => $certificate->id,
        ])
        ->assertRedirect();

    $revision = $revision->fresh();

    expect($revision->tax_calculation_status)->toBe(QuoteTaxCalculationStatus::Exempt)
        ->and($revision->tax_cents)->toBe(0);
});

test('phase 2c2 http an unverified certificate leaves the quote needing review', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision, 'organizationCompany' => $organizationCompany]
        = Phase2C2Fixture::draftQuote('admin');
    Phase2C2Fixture::taxProfile($ctx);
    $rate = Phase2C2Fixture::taxRate($ctx);
    $pending = Phase2C2Fixture::certificate($ctx, $organizationCompany);

    $this->actingAs($ctx['user'])
        ->post(phase2c2HttpRevisionRoute('org.quotes.revisions.tax.calculate', $ctx, $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'organization_tax_rate_id' => $rate->id,
            'certificate_id' => $pending->id,
        ])
        ->assertRedirect();

    $revision = $revision->fresh();

    expect($revision->tax_calculation_status)->toBe(QuoteTaxCalculationStatus::ReviewRequired);

    $this->actingAs($ctx['user'])
        ->get(phase2c2HttpRevisionRoute('org.quotes.revisions.edit', $ctx, $quote, $revision))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tax.status', 'review_required')
            ->where('approval.blocked_by_tax', true)
            ->where('approval.can_submit', false)
            ->where('tax.review_reasons.0', 'certificate_pending_verification'));

    // Submission is refused by the workflow, not only hidden by the panel.
    $this->actingAs($ctx['user'])
        ->postJson(phase2c2HttpRevisionRoute('org.quotes.revisions.approvals.submit', $ctx, $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'expected_quote_lock_version' => $quote->fresh()->lock_version,
        ])
        ->assertStatus(422);
});

test('phase 2c2 http overriding tax needs override authority', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = Phase2C2Fixture::draftQuote('salesperson');
    Phase2C2Fixture::taxProfile($ctx);
    $rate = Phase2C2Fixture::taxRate($ctx);

    // A salesperson may resolve tax from a configured rate.
    $this->actingAs($ctx['user'])
        ->post(phase2c2HttpRevisionRoute('org.quotes.revisions.tax.calculate', $ctx, $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'organization_tax_rate_id' => $rate->id,
        ])
        ->assertRedirect();

    $revision = $revision->fresh();

    $this->actingAs($ctx['user'])
        ->post(phase2c2HttpRevisionRoute('org.quotes.revisions.tax.override', $ctx, $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'override_tax' => '10.00',
            'reason' => 'Negotiated flat tax.',
        ])
        ->assertForbidden();

    expect($revision->fresh()->tax_cents)->toBe(8_000);

    $this->actingAs($ctx['user'])
        ->get(phase2c2HttpRevisionRoute('org.quotes.revisions.edit', $ctx, $quote, $revision))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tax.can_calculate', true)
            ->where('tax.can_override', false));

    // The same call from a role that holds override authority is accepted.
    $overrider = Phase2C2Fixture::draftQuote('admin');
    Phase2C2Fixture::taxProfile($overrider['ctx']);
    $overriderRate = Phase2C2Fixture::taxRate($overrider['ctx']);

    $this->actingAs($overrider['ctx']['user'])
        ->post(phase2c2HttpRevisionRoute(
            'org.quotes.revisions.tax.override',
            $overrider['ctx'],
            $overrider['quote'],
            $overrider['revision'],
        ), [
            'expected_lock_version' => $overrider['revision']->lock_version,
            'organization_tax_rate_id' => $overriderRate->id,
            'override_tax' => '10.00',
            'reason' => 'Negotiated flat tax.',
        ])
        ->assertRedirect();

    expect($overrider['revision']->fresh()->tax_cents)->toBe(1_000);
});

test('phase 2c2 http a quote is submitted, approved, and never sent as a side effect', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = Phase2C2Fixture::draftQuote('admin');
    phase2c2HttpResolveTax($ctx, $quote, $revision);

    $quote = $quote->fresh();
    $revision = $revision->fresh();

    $this->actingAs($ctx['user'])
        ->post(phase2c2HttpRevisionRoute('org.quotes.revisions.approvals.submit', $ctx, $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'expected_quote_lock_version' => $quote->lock_version,
        ])
        ->assertRedirect();

    $revision = $revision->fresh();
    $approvalRequest = QuoteApprovalRequest::query()->sole();

    expect($revision->status)->toBe(QuoteRevisionStatus::PendingApproval)
        ->and($approvalRequest->status)->toBe(QuoteApprovalRequestStatus::Pending)
        ->and($approvalRequest->rule_snapshot_json['reasons'])->toContain('new_customer');

    $this->actingAs($ctx['user'])
        ->get(route('org.quote-approvals.index', $ctx['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('quotes/ApprovalQueue')
            ->has('requests', 1)
            ->where('requests.0.quote_number', $quote->quote_number)
            ->where('requests.0.is_open', true));

    $this->actingAs($ctx['user'])
        ->post(route('org.quote-approvals.approve', [$ctx['organization'], $approvalRequest]), [
            'expected_lock_version' => $revision->lock_version,
            'expected_quote_lock_version' => $quote->fresh()->lock_version,
        ])
        ->assertRedirect(route('org.quote-approvals.index', $ctx['organization']));

    $revision = $revision->fresh();

    expect($revision->status)->toBe(QuoteRevisionStatus::Approved)
        ->and($approvalRequest->fresh()->status)->toBe(QuoteApprovalRequestStatus::Approved)
        // Approving decides the quote internally; it does not reach the customer.
        ->and($quote->fresh()->lifecycle_status)->toBe(QuoteLifecycleStatus::Open)
        ->and(AuditEvent::query()->where('action', 'like', '%sent%')->count())->toBe(0);

    $this->actingAs($ctx['user'])
        ->post(phase2c2HttpRevisionRoute('org.quotes.revisions.approvals.return-to-draft', $ctx, $quote->fresh(), $revision), [
            'expected_lock_version' => $revision->lock_version,
            'expected_quote_lock_version' => $quote->fresh()->lock_version,
        ])
        ->assertRedirect();

    expect($revision->fresh()->status)->toBe(QuoteRevisionStatus::Draft);
});

test('phase 2c2 http an approval request can be withdrawn and a later one rejected', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = Phase2C2Fixture::draftQuote('admin');
    phase2c2HttpResolveTax($ctx, $quote, $revision);

    $submit = function () use ($ctx, $quote, $revision): void {
        test()->actingAs($ctx['user'])
            ->post(phase2c2HttpRevisionRoute('org.quotes.revisions.approvals.submit', $ctx, $quote->fresh(), $revision->fresh()), [
                'expected_lock_version' => $revision->fresh()->lock_version,
                'expected_quote_lock_version' => $quote->fresh()->lock_version,
            ])
            ->assertRedirect();
    };

    $submit();

    $this->actingAs($ctx['user'])
        ->post(phase2c2HttpRevisionRoute('org.quotes.revisions.approvals.withdraw', $ctx, $quote->fresh(), $revision->fresh()), [
            'expected_lock_version' => $revision->fresh()->lock_version,
            'expected_quote_lock_version' => $quote->fresh()->lock_version,
        ])
        ->assertRedirect();

    expect($revision->fresh()->status)->toBe(QuoteRevisionStatus::Draft)
        ->and(QuoteApprovalRequest::query()->sole()->status)->toBe(QuoteApprovalRequestStatus::Cancelled);

    $submit();

    $pending = QuoteApprovalRequest::query()
        ->where('status', QuoteApprovalRequestStatus::Pending)
        ->sole();

    $this->actingAs($ctx['user'])
        ->postJson(route('org.quote-approvals.reject', [$ctx['organization'], $pending]), [
            'expected_lock_version' => $revision->fresh()->lock_version,
            'expected_quote_lock_version' => $quote->fresh()->lock_version,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    $this->actingAs($ctx['user'])
        ->post(route('org.quote-approvals.reject', [$ctx['organization'], $pending]), [
            'expected_lock_version' => $revision->fresh()->lock_version,
            'expected_quote_lock_version' => $quote->fresh()->lock_version,
            'reason' => 'Margin is too thin.',
        ])
        ->assertRedirect();

    expect($pending->fresh()->status)->toBe(QuoteApprovalRequestStatus::Rejected)
        ->and($revision->fresh()->status)->toBe(QuoteRevisionStatus::Draft);
});

test('phase 2c2 http the approval queue is closed without approve authority', function () {
    $ctx = createTenantUser('salesperson');

    $this->actingAs($ctx['user'])
        ->get(route('org.quote-approvals.index', $ctx['organization']))
        ->assertForbidden();
});

test('phase 2c2 http tax and approval records from another organization are not reachable', function () {
    ['ctx' => $owner, 'quote' => $quote, 'revision' => $revision, 'organizationCompany' => $organizationCompany]
        = Phase2C2Fixture::draftQuote('admin');
    phase2c2HttpResolveTax($owner, $quote, $revision);

    $rate = OrganizationTaxRate::query()->where('organization_id', $owner['organization']->id)->sole();
    $certificate = Phase2C2Fixture::certificate($owner, $organizationCompany);
    $company = phase2c2HttpCompany($organizationCompany->company_id);

    $this->actingAs($owner['user'])
        ->post(phase2c2HttpRevisionRoute('org.quotes.revisions.approvals.submit', $owner, $quote->fresh(), $revision->fresh()), [
            'expected_lock_version' => $revision->fresh()->lock_version,
            'expected_quote_lock_version' => $quote->fresh()->lock_version,
        ])
        ->assertRedirect();

    $approvalRequest = QuoteApprovalRequest::query()->sole();

    $intruder = createTenantUser('admin');

    $this->actingAs($intruder['user'])
        ->patch(route('org.tax-settings.rates.update', [$intruder['organization'], $rate]), [
            'display_name' => 'Hijacked',
        ])
        ->assertNotFound();

    $this->actingAs($intruder['user'])
        ->get(route('org.companies.tax-certificates.index', [$intruder['organization'], $company]))
        ->assertNotFound();

    $this->actingAs($intruder['user'])
        ->post(route('org.companies.tax-certificates.verify', [$intruder['organization'], $company, $certificate]))
        ->assertNotFound();

    $this->actingAs($intruder['user'])
        ->post(route('org.quote-approvals.approve', [$intruder['organization'], $approvalRequest]), [
            'expected_lock_version' => 1,
            'expected_quote_lock_version' => 1,
        ])
        ->assertNotFound();

    $this->actingAs($intruder['user'])
        ->post(phase2c2HttpRevisionRoute('org.quotes.revisions.tax.calculate', $intruder, $quote, $revision), [
            'expected_lock_version' => 1,
            'organization_tax_rate_id' => $rate->id,
        ])
        ->assertNotFound();
});

test('phase 2c2 http a stale lock version is refused', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = Phase2C2Fixture::draftQuote('admin');
    Phase2C2Fixture::taxProfile($ctx);
    $rate = Phase2C2Fixture::taxRate($ctx);

    $staleLockVersion = $revision->lock_version;

    $this->actingAs($ctx['user'])
        ->post(phase2c2HttpRevisionRoute('org.quotes.revisions.tax.calculate', $ctx, $quote, $revision), [
            'expected_lock_version' => $staleLockVersion,
            'organization_tax_rate_id' => $rate->id,
        ])
        ->assertRedirect();

    $this->actingAs($ctx['user'])
        ->post(phase2c2HttpRevisionRoute('org.quotes.revisions.tax.calculate', $ctx, $quote->fresh(), $revision->fresh()), [
            'expected_lock_version' => $staleLockVersion,
            'organization_tax_rate_id' => $rate->id,
        ])
        ->assertStatus(409);

    $revision = $revision->fresh();
    $quote = $quote->fresh();

    $this->actingAs($ctx['user'])
        ->post(phase2c2HttpRevisionRoute('org.quotes.revisions.approvals.submit', $ctx, $quote, $revision), [
            'expected_lock_version' => $staleLockVersion,
            'expected_quote_lock_version' => $quote->lock_version,
        ])
        ->assertStatus(409);

    expect($revision->fresh()->status)->toBe(QuoteRevisionStatus::Draft);
});

test('phase 2c2 http a company with no verified certificate says so on the certificates page', function () {
    ['ctx' => $ctx, 'organizationCompany' => $organizationCompany] = Phase2C2Fixture::draftQuote('admin');
    $company = phase2c2HttpCompany($organizationCompany->company_id);

    $this->actingAs($ctx['user'])
        ->get(route('org.companies.tax-certificates.index', [$ctx['organization'], $company]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('companies/TaxCertificates')
            ->has('certificates', 0)
            ->where('canManage', true)
            ->has('exemptionCategories'));

    $this->actingAs($ctx['user'])
        ->get(route('org.companies.show', [$ctx['organization'], $company]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('companies/Show')
            ->where('canViewTaxCertificates', true)
            ->where('taxCertificatesUrl', route(
                'org.companies.tax-certificates.index',
                [$ctx['organization'], $company],
            )));
});
