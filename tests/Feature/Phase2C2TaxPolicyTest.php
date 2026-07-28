<?php

use App\Models\OrganizationTaxProfile;
use App\Models\OrganizationTaxRate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Phase2C2Fixture;

afterEach(function () {
    TenantContext::clear();
});

test('an owner may approve, calculate, and override tax on their own organization quote', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'organizationCompany' => $company] = Phase2C2Fixture::draftQuote('owner');
    $profile = Phase2C2Fixture::taxProfile($ctx);
    $rate = Phase2C2Fixture::taxRate($ctx);
    $certificate = Phase2C2Fixture::certificate($ctx, $company);

    Phase2C2Fixture::establishTenant($ctx);
    $gate = Gate::forUser($ctx['user']);

    expect($gate->allows('approve', $quote))->toBeTrue()
        ->and($gate->allows('calculateTax', $quote))->toBeTrue()
        ->and($gate->allows('overrideTax', $quote))->toBeTrue()
        ->and($gate->allows('view', $certificate))->toBeTrue()
        ->and($gate->allows('update', $certificate))->toBeTrue()
        ->and($gate->allows('decide', $certificate))->toBeTrue()
        ->and($gate->allows('createFor', [$certificate::class, $company]))->toBeTrue()
        ->and($gate->allows('view', $profile))->toBeTrue()
        ->and($gate->allows('update', $profile))->toBeTrue()
        ->and($gate->allows('view', $rate))->toBeTrue()
        ->and($gate->allows('update', $rate))->toBeTrue()
        ->and($gate->allows('deactivate', $rate))->toBeTrue();
});

test('a salesperson may resolve tax but never override it, approve, or decide certificates', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'organizationCompany' => $company] = Phase2C2Fixture::draftQuote('salesperson');
    $profile = Phase2C2Fixture::taxProfile($ctx);
    $rate = Phase2C2Fixture::taxRate($ctx);
    $certificate = Phase2C2Fixture::certificate($ctx, $company);

    Phase2C2Fixture::establishTenant($ctx);
    $gate = Gate::forUser($ctx['user']);

    expect($gate->allows('calculateTax', $quote))->toBeTrue()
        ->and($gate->denies('overrideTax', $quote))->toBeTrue()
        ->and($gate->denies('approve', $quote))->toBeTrue()
        // Reading a certificate record is not the same authority as acting on it.
        ->and($gate->allows('view', $certificate))->toBeTrue()
        ->and($gate->denies('update', $certificate))->toBeTrue()
        ->and($gate->denies('decide', $certificate))->toBeTrue()
        // Configuration is readable for picking a jurisdiction, but not writable.
        ->and($gate->allows('view', $profile))->toBeTrue()
        ->and($gate->denies('update', $profile))->toBeTrue()
        ->and($gate->allows('view', $rate))->toBeTrue()
        ->and($gate->denies('update', $rate))->toBeTrue();
});

test('tax authority never reaches across organizations', function () {
    ['ctx' => $ctx, 'organizationCompany' => $company] = Phase2C2Fixture::draftQuote('owner');
    $certificate = Phase2C2Fixture::certificate($ctx, $company);

    $foreign = Phase2C2Fixture::draftQuote('owner');
    $foreignProfile = Phase2C2Fixture::taxProfile($foreign['ctx']);
    $foreignRate = Phase2C2Fixture::taxRate($foreign['ctx']);
    $foreignCertificate = Phase2C2Fixture::certificate($foreign['ctx'], $foreign['organizationCompany']);

    Phase2C2Fixture::establishTenant($ctx);
    $gate = Gate::forUser($ctx['user']);

    expect($gate->denies('approve', $foreign['quote']))->toBeTrue()
        ->and($gate->denies('calculateTax', $foreign['quote']))->toBeTrue()
        ->and($gate->denies('view', $foreignCertificate))->toBeTrue()
        ->and($gate->denies('decide', $foreignCertificate))->toBeTrue()
        ->and($gate->denies('createFor', [$certificate::class, $foreign['organizationCompany']]))->toBeTrue()
        ->and($gate->denies('view', $foreignProfile))->toBeTrue()
        ->and($gate->denies('update', $foreignRate))->toBeTrue();
});

test('no tax authority exists without a tenant in scope', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'organizationCompany' => $company] = Phase2C2Fixture::draftQuote('owner');
    $certificate = Phase2C2Fixture::certificate($ctx, $company);
    Phase2C2Fixture::taxProfile($ctx);
    Phase2C2Fixture::taxRate($ctx);

    TenantContext::clear();
    $gate = Gate::forUser($ctx['user']);

    expect($gate->denies('approve', $quote))->toBeTrue()
        ->and($gate->denies('calculateTax', $quote))->toBeTrue()
        ->and($gate->denies('overrideTax', $quote))->toBeTrue()
        ->and($gate->denies('view', $certificate))->toBeTrue()
        ->and($gate->denies('viewAny', OrganizationTaxProfile::class))->toBeTrue()
        ->and($gate->denies('viewAny', OrganizationTaxRate::class))->toBeTrue();
});
