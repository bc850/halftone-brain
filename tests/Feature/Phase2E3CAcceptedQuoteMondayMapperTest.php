<?php

use App\Enums\IntegrationProvider;
use App\Enums\IntegrationValidationStatus;
use App\Models\OrganizationIntegrationSetting;
use App\Support\Integrations\Monday\AcceptedQuoteMondayMapper;
use App\Support\Integrations\Monday\MondaySensitivePayloadGuard;
use Tests\Support\Phase2C2Fixture;
use Tests\Support\Phase2E3CHelpers;

beforeEach(function (): void {
    $this->withoutVite();
});

test('phase 2e3c mapper formats money cents to decimal strings including negatives', function () {
    $mapper = app(AcceptedQuoteMondayMapper::class);

    expect($mapper->centsToDecimalString(10000))->toBe('100.00')
        ->and($mapper->centsToDecimalString(101))->toBe('1.01')
        ->and($mapper->centsToDecimalString(-250))->toBe('-2.50');
});

test('phase 2e3c mapper derives pretax tax and grand totals from accepted revision snapshots', function () {
    $fixture = phase2e3cAcceptedQuoteFixture();
    $request = app(AcceptedQuoteMondayMapper::class)->map(
        quote: $fixture['quote'],
        revision: $fixture['revision'],
        organization: $fixture['ctx']['organization'],
        settings: $fixture['settings'],
        party: $fixture['party'],
        tax: $fixture['revision']->currentTaxCalculation,
        deliveryIdempotencyKey: 'map-key-1',
    );

    expect($request->columnValues['numbers_pretax_total'] ?? null)->toBe('100.00')
        ->and($request->columnValues['numbers_tax_total'] ?? null)->toBe('8.00')
        ->and($request->columnValues['numbers_grand_total'] ?? null)->toBe('108.00');
});

test('phase 2e3c mapper renders item name from template and omits expiration date everywhere', function () {
    $fixture = phase2e3cAcceptedQuoteFixture();
    $request = app(AcceptedQuoteMondayMapper::class)->map(
        quote: $fixture['quote'],
        revision: $fixture['revision'],
        organization: $fixture['ctx']['organization'],
        settings: $fixture['settings'],
        party: $fixture['party'],
        tax: $fixture['revision']->currentTaxCalculation,
        deliveryIdempotencyKey: 'map-key-2',
    );

    $companyName = (string) ($fixture['party']?->customer_company_name ?? 'Customer');

    expect($request->itemName)->toBe("{$fixture['quote']->quote_number} — {$companyName}")
        ->and($request->itemName)->not->toContain('2099-12-31')
        ->and(json_encode($request->columnValues))->not->toContain('2099-12-31')
        ->and(json_encode($request->columnValues))->not->toContain('expiration_date');
});

test('phase 2e3c mapper formats each supported monday column type', function () {
    $fixture = phase2e3cAcceptedQuoteFixture();
    $request = app(AcceptedQuoteMondayMapper::class)->map(
        quote: $fixture['quote'],
        revision: $fixture['revision'],
        organization: $fixture['ctx']['organization'],
        settings: $fixture['settings'],
        party: $fixture['party'],
        tax: $fixture['revision']->currentTaxCalculation,
        deliveryIdempotencyKey: 'map-key-3',
    );

    $acceptedDate = ($fixture['revision']->accepted_at ?? now())->toDateString();
    $halftoneLink = $request->columnValues['link_halftone'] ?? [];

    expect($request->columnValues['text_integration_key'] ?? null)->toBeString()
        ->and($request->columnValues['text_quote_number'] ?? null)->toBe((string) $fixture['quote']->quote_number)
        ->and($request->columnValues['text_company_name'] ?? null)->toBeString()
        ->and($request->columnValues['date_accepted'] ?? null)->toBe(['date' => $acceptedDate])
        ->and($request->columnValues['numbers_grand_total'] ?? null)->toBe('108.00')
        ->and($request->columnValues['status_intake'] ?? null)->toBe(['label' => 'New Intake'])
        ->and($halftoneLink['text'] ?? null)->toBe('Open in Halftone Brain')
        ->and($halftoneLink['url'] ?? '')->not->toBe('')
        ->and($request->columnValues['long_text_line_summary'] ?? null)->toBeString();
});

test('phase 2e3c mapper omits optional columns when party contact fields are empty', function () {
    $fixture = phase2e3cAcceptedQuoteFixture(withOptionalPartyFields: false);

    $request = app(AcceptedQuoteMondayMapper::class)->map(
        quote: $fixture['quote'],
        revision: $fixture['revision'],
        organization: $fixture['ctx']['organization'],
        settings: $fixture['settings'],
        party: $fixture['party'],
        tax: $fixture['revision']->currentTaxCalculation,
        deliveryIdempotencyKey: 'map-key-4',
    );

    expect($request->columnValues)->not->toHaveKey('text_primary_contact')
        ->and($request->columnValues)->not->toHaveKey('text_salesperson');
});

test('phase 2e3c mapper bounds line summary to five hundred characters', function () {
    $fixture = Phase2C2Fixture::draftQuote();
    phase2e3cEstablishTenant($fixture['ctx']);

    foreach (range(1, 40) as $index) {
        Phase2C2Fixture::addTaxableLine(
            $fixture['ctx'],
            $fixture['quote'],
            $fixture['revision'],
            unitPriceCents: 100,
            quantity: '1',
        );
    }

    $revision = Phase2E3CHelpers::acceptRevision($fixture['ctx'], $fixture['quote'], $fixture['revision']);

    $settings = OrganizationIntegrationSetting::factory()->create([
        'organization_id' => $fixture['ctx']['organization']->id,
        'parent_account_id' => $fixture['ctx']['parent']->id,
        'provider' => IntegrationProvider::Monday,
        'board_id' => 'fake_board_100',
        'group_id' => 'fake_group_100',
        'enabled' => true,
        'last_validation_status' => IntegrationValidationStatus::Valid,
        'last_validated_at' => now(),
        'last_validation_error_code' => null,
        'api_version' => '2026-07',
        'column_mapping_json' => phase2e3cFullColumnMapping(),
    ]);

    $request = app(AcceptedQuoteMondayMapper::class)->map(
        quote: $fixture['quote'],
        revision: $revision,
        organization: $fixture['ctx']['organization'],
        settings: $settings,
        party: $revision->partySnapshot,
        tax: $revision->currentTaxCalculation,
        deliveryIdempotencyKey: 'map-key-5',
    );

    $summary = $request->columnValues['long_text_line_summary'] ?? '';

    expect(mb_strlen((string) $summary))->toBeLessThanOrEqual(500)
        ->and(str_ends_with((string) $summary, '…'))->toBeTrue();
});

test('phase 2e3c mapper halftone url uses internal org route not customer token path', function () {
    $fixture = phase2e3cAcceptedQuoteFixture();
    $organization = $fixture['ctx']['organization'];

    $request = app(AcceptedQuoteMondayMapper::class)->map(
        quote: $fixture['quote'],
        revision: $fixture['revision'],
        organization: $organization,
        settings: $fixture['settings'],
        party: $fixture['party'],
        tax: $fixture['revision']->currentTaxCalculation,
        deliveryIdempotencyKey: 'map-key-6',
    );

    $link = $request->columnValues['link_halftone']['url'] ?? '';

    expect($link)->toContain('/'.$organization->slug.'/')
        ->and($link)->toContain('/quotes/'.$fixture['quote']->id)
        ->and($link)->not->toContain('/public/')
        ->and($link)->not->toContain('token');
});

test('phase 2e3c mapper output is deterministic for identical inputs', function () {
    $fixture = phase2e3cAcceptedQuoteFixture();
    $mapper = app(AcceptedQuoteMondayMapper::class);

    $first = $mapper->map(
        quote: $fixture['quote'],
        revision: $fixture['revision'],
        organization: $fixture['ctx']['organization'],
        settings: $fixture['settings'],
        party: $fixture['party'],
        tax: $fixture['revision']->currentTaxCalculation,
        deliveryIdempotencyKey: 'stable-key',
    );

    $second = $mapper->map(
        quote: $fixture['quote'],
        revision: $fixture['revision'],
        organization: $fixture['ctx']['organization'],
        settings: $fixture['settings'],
        party: $fixture['party'],
        tax: $fixture['revision']->currentTaxCalculation,
        deliveryIdempotencyKey: 'stable-key',
    );

    expect($first->itemName)->toBe($second->itemName)
        ->and($first->integrationKey)->toBe($second->integrationKey)
        ->and($first->columnValues)->toEqual($second->columnValues);
});

test('phase 2e3c mapper column values exclude forbidden commercial and credential keys', function () {
    $fixture = phase2e3cAcceptedQuoteFixture();
    $request = app(AcceptedQuoteMondayMapper::class)->map(
        quote: $fixture['quote'],
        revision: $fixture['revision'],
        organization: $fixture['ctx']['organization'],
        settings: $fixture['settings'],
        party: $fixture['party'],
        tax: $fixture['revision']->currentTaxCalculation,
        deliveryIdempotencyKey: 'map-key-7',
    );

    $encoded = json_encode($request->columnValues) ?: '';

    foreach (['cost', 'margin', 'markup', 'approval', 'certificate', 'token', 'path', 'expiration_date'] as $forbidden) {
        expect(strtolower($encoded))->not->toContain(strtolower($forbidden));
    }

    expect(MondaySensitivePayloadGuard::FORBIDDEN_KEYS)->toContain('expiration_date');
});
