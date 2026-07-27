<?php

use App\Enums\PricingMethod;
use App\Enums\QuoteAdjustmentMethod;
use App\Enums\QuoteAdjustmentType;
use App\Enums\QuoteLineDiscountMethod;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\OrganizationProduct;
use App\Models\QuoteRevisionLineItem;
use App\Support\Quotes\InvalidQuoteDraftException;
use App\Support\Quotes\QuoteDraftAdjustmentService;
use App\Support\Quotes\QuoteDraftLineService;
use App\Support\Quotes\QuoteFactoryService;
use App\Support\Quotes\QuotePartySnapshotService;
use App\Support\Quotes\QuoteRepriceService;
use App\Support\Quotes\QuoteRevisionCloner;
use App\Support\Quotes\StaleQuoteStateException;

test('phase 2b2 draft editing flows from creation through clone', function () {
    $ctx = createTenantUser('salesperson');
    $deal = Deal::factory()->create([
        'organization_id' => $ctx['organization']->id,
        'parent_account_id' => $ctx['parent']->id,
        'owner_id' => $ctx['user']->id,
    ]);
    $company = Company::query()->findOrFail($deal->company_id);
    $contact = Contact::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'company_id' => $company->id,
    ]);

    $quote = app(QuoteFactoryService::class)->create(
        deal: $deal,
        createdByMembership: $ctx['membership'],
        organization: $ctx['organization'],
        quotePrefix: 'B2-Q-',
        padLength: 5,
        salesOwnerMembership: $ctx['membership'],
        actor: $ctx['user'],
        primaryContact: $contact,
        expirationDate: '2026-12-31',
        customerPoReference: 'PO-9',
        introduction: 'Hello',
        termsText: 'Terms',
        customerNotes: 'Notes',
        internalNotes: 'Internal',
    );

    $revision = $quote->currentRevision;
    $snapshot = $revision->partySnapshot;

    expect($snapshot->primary_contact_id)->toBe($contact->id)
        ->and($snapshot->customer_po_reference)->toBe('PO-9')
        ->and($snapshot->salesperson_membership_id)->toBe($ctx['membership']->id)
        ->and($revision->introduction)->toBe('Hello')
        ->and($revision->expiration_date->toDateString())->toBe('2026-12-31');

    $organizationProduct = OrganizationProduct::factory()->create([
        'organization_id' => $ctx['organization']->id,
        'parent_account_id' => $ctx['parent']->id,
        'pricing_method' => PricingMethod::Fixed,
        'fixed_price_cents' => 25_000,
        'allow_price_override' => true,
    ]);

    $lines = app(QuoteDraftLineService::class);

    $line = $lines->addCatalogLine(
        quote: $quote,
        revision: $revision,
        expectedRevisionLockVersion: $revision->lock_version,
        organizationProduct: $organizationProduct,
        quantity: '2',
        actor: $ctx['user'],
    );

    $revision = $revision->fresh();
    expect($line->final_unit_price_cents)->toBe(25_000)
        ->and($line->position)->toBe(1)
        ->and($revision->subtotal_cents)->toBe(50_000)
        ->and($revision->grand_total_cents)->toBe(50_000)
        ->and($revision->lock_version)->toBe(2)
        ->and($revision->approval_required)->toBeFalse();

    expect(fn () => $lines->addSectionLine($quote, $revision, 1, 'Stale'))
        ->toThrow(StaleQuoteStateException::class);

    $section = $lines->addSectionLine($quote, $revision, $revision->lock_version, 'Materials', actor: $ctx['user']);
    $revision = $revision->fresh();

    $custom = $lines->addCustomLine(
        quote: $quote,
        revision: $revision,
        expectedRevisionLockVersion: $revision->lock_version,
        name: 'Rush setup',
        quantity: '1',
        unitPriceCents: 120_000,
        reason: 'Customer requested rush',
        mayOverride: true,
        actor: $ctx['user'],
    );
    $revision = $revision->fresh();

    expect($custom->approval_required)->toBeTrue()
        ->and($revision->approval_required)->toBeTrue()
        ->and($revision->approval_reason_snapshot['reasons'])->toContain('custom_line')
        ->and($revision->approval_reason_snapshot['meets_monetary_threshold'])->toBeTrue()
        ->and($revision->grand_total_cents)->toBe(170_000);

    expect(fn () => $lines->addCustomLine(
        quote: $quote,
        revision: $revision,
        expectedRevisionLockVersion: $revision->lock_version,
        name: 'No authority',
        quantity: '1',
        unitPriceCents: 1,
        reason: 'nope',
    ))->toThrow(InvalidQuoteDraftException::class);

    $lines->updateLine(
        quote: $quote,
        revision: $revision,
        expectedRevisionLockVersion: $revision->lock_version,
        line: $line,
        data: [
            'line_discount_method' => QuoteLineDiscountMethod::Percentage,
            'line_discount_value' => 1000,
        ],
        actor: $ctx['user'],
    );
    $revision = $revision->fresh();
    expect($line->fresh()->line_discount_amount_cents)->toBe(5_000)
        ->and($revision->subtotal_cents)->toBe(165_000);

    $reordered = $lines->reorderLines(
        $quote,
        $revision,
        $revision->lock_version,
        [$custom->id, $section->id, $line->id],
        $ctx['user'],
    );
    $revision = $revision->fresh();
    expect($reordered->pluck('id')->all())->toBe([$custom->id, $section->id, $line->id]);

    $adjustments = app(QuoteDraftAdjustmentService::class);
    $shipping = $adjustments->add(
        quote: $quote,
        revision: $revision,
        expectedRevisionLockVersion: $revision->lock_version,
        adjustmentType: QuoteAdjustmentType::Shipping,
        description: 'Freight',
        method: QuoteAdjustmentMethod::Fixed,
        inputValue: 4_500,
        isTaxable: true,
        actor: $ctx['user'],
    );
    $revision = $revision->fresh();
    expect($shipping->fresh()->amount_cents)->toBe(4_500)
        ->and($revision->grand_total_cents)->toBe(169_500);

    expect(fn () => $adjustments->add(
        quote: $quote,
        revision: $revision,
        expectedRevisionLockVersion: $revision->lock_version,
        adjustmentType: QuoteAdjustmentType::QuoteDiscount,
        description: 'Loyalty',
        method: QuoteAdjustmentMethod::Fixed,
        inputValue: 1_000,
    ))->toThrow(InvalidQuoteDraftException::class);

    $adjustments->add(
        quote: $quote,
        revision: $revision,
        expectedRevisionLockVersion: $revision->lock_version,
        adjustmentType: QuoteAdjustmentType::QuoteDiscount,
        description: 'Loyalty',
        method: QuoteAdjustmentMethod::Fixed,
        inputValue: 1_000,
        reason: 'Repeat customer',
        mayOverride: true,
        actor: $ctx['user'],
    );
    $revision = $revision->fresh();
    expect($revision->grand_total_cents)->toBe(168_500)
        ->and($revision->approval_reason_snapshot['reasons'])->toContain('quote_discount');

    $organizationProduct->forceFill(['fixed_price_cents' => 30_000, 'pricing_version' => 2])->save();
    app(QuoteRepriceService::class)->repriceLine(
        $quote,
        $revision,
        $revision->lock_version,
        $line->fresh(),
        true,
        $ctx['user'],
    );
    $revision = $revision->fresh();
    expect($line->fresh()->calculated_unit_price_cents)->toBe(30_000)
        ->and($line->fresh()->pricing_version_snapshot)->toBe(2);

    $company->forceFill(['name' => 'Renamed Co'])->save();
    $preview = app(QuotePartySnapshotService::class)->previewRefresh($revision);
    expect(collect($preview['changes'])->pluck('field')->all())->toContain('customer_company_name')
        ->and($revision->partySnapshot->customer_company_name)->not->toBe('Renamed Co');

    $refreshed = app(QuotePartySnapshotService::class)->refreshFromCustomer(
        $quote,
        $revision,
        $revision->lock_version,
        $ctx['user'],
    );
    expect($refreshed->customer_company_name)->toBe('Renamed Co')
        ->and($refreshed->customer_po_reference)->toBe('PO-9');

    $revision = $revision->fresh();

    $clone = app(QuoteRevisionCloner::class)->cloneToDraft(
        quote: $quote->fresh(),
        source: $revision,
        expectedQuoteLockVersion: $quote->fresh()->lock_version,
        actor: $ctx['user'],
    );

    expect(QuoteRevisionLineItem::query()->where('quote_revision_id', $clone->id)->count())->toBe(3)
        ->and($clone->adjustments()->count())->toBe(2)
        ->and($clone->partySnapshot?->customer_company_name)->toBe('Renamed Co')
        ->and($clone->partySnapshot?->id)->not->toBe($refreshed->id)
        ->and($clone->tax_snapshot_json)->toBeNull()
        ->and($clone->approval_required)->toBeFalse();

    $clonedCustomLine = QuoteRevisionLineItem::query()
        ->where('quote_revision_id', $clone->id)
        ->where('line_type', 'custom')
        ->firstOrFail();
    expect($clonedCustomLine->approval_required)->toBeTrue()
        ->and($clonedCustomLine->approval_reason_json['reasons'])->toBe(['custom_line']);

    $lines->removeLine($quote->fresh(), $clone, $clone->lock_version, $clonedCustomLine, $ctx['user']);
    expect($clone->fresh()->grand_total_cents)->toBe(57_500);
});
