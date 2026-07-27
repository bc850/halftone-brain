<?php

use App\Enums\DealStage;
use App\Enums\PermissionEffect;
use App\Enums\PricingMethod;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\ParentAccount;
use App\Models\ParentAccountMembership;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionAdjustment;
use App\Models\QuoteRevisionLineItem;
use App\Models\User;
use App\Support\Quotes\QuoteFactoryService;
use App\Support\Tenancy\NumberSequenceAllocator;
use Database\Factories\QuoteFactory;

/**
 * @return array{
 *     user: User,
 *     parent: ParentAccount,
 *     organization: Organization,
 *     membership: Membership,
 *     parentMembership: ParentAccountMembership|null
 * }
 */
function phase2b2Context(string $role = 'admin', ?string $slug = null): array
{
    $ctx = createTenantUser($role);

    if ($slug !== null) {
        $ctx['organization']->forceFill(['slug' => $slug])->save();
        $ctx['organization'] = $ctx['organization']->fresh();
    }

    return $ctx;
}

/**
 * @param  array{user: User, parent: ParentAccount, organization: Organization, membership: Membership}  $ctx
 */
function phase2b2Deal(array $ctx, DealStage $stage = DealStage::Lead): Deal
{
    return Deal::factory()->create([
        'organization_id' => $ctx['organization']->id,
        'parent_account_id' => $ctx['parent']->id,
        'owner_id' => $ctx['user']->id,
        'stage' => $stage,
    ]);
}

/**
 * @param  array{organization: Organization, parent: ParentAccount}  $ctx
 */
function phase2b2Product(array $ctx, int $fixedPriceCents = 25_000, ?int $minimumPriceCents = null): OrganizationProduct
{
    return OrganizationProduct::factory()->create([
        'organization_id' => $ctx['organization']->id,
        'parent_account_id' => $ctx['parent']->id,
        'pricing_method' => PricingMethod::Fixed,
        'fixed_price_cents' => $fixedPriceCents,
        'minimum_price_cents' => $minimumPriceCents,
        'allow_price_override' => true,
    ]);
}

/**
 * @return array{0: Quote, 1: QuoteRevision}
 */
function phase2b2Draft(Deal $deal, Membership $membership): array
{
    $quote = QuoteFactory::createForDeal($deal, $membership);

    return [$quote, $quote->currentRevision];
}

function phase2b2RevisionRoute(string $name, Organization $org, Quote $quote, QuoteRevision $revision, mixed $child = null): string
{
    $parameters = [$org, $quote, $revision];

    if ($child !== null) {
        $parameters[] = $child;
    }

    return route($name, $parameters);
}

test('phase 2b2 http create allocates the org quote number and moves the deal to quoting', function () {
    $ctx = phase2b2Context('admin', 'pelican-signs');
    $deal = phase2b2Deal($ctx);
    $contact = Contact::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'company_id' => $deal->company_id,
    ]);

    $this->actingAs($ctx['user'])
        ->post(route('org.deals.quotes.store', [$ctx['organization'], $deal]), [
            'sales_owner_membership_id' => $ctx['membership']->id,
            'primary_contact_id' => $contact->id,
            'expiration_date' => '2026-12-31',
            'customer_po_reference' => 'PO-77',
            'introduction' => 'Thanks for the opportunity.',
        ])
        ->assertRedirect();

    $quote = Quote::query()->where('deal_id', $deal->id)->sole();

    expect($quote->quote_number)->toBe('PEL-Q-00001')
        ->and($quote->currentRevision->revision_number)->toBe(1)
        ->and($quote->currentRevision->partySnapshot?->customer_po_reference)->toBe('PO-77')
        ->and($deal->fresh()->stage)->toBe(DealStage::Quoting);

    expect(AuditEvent::query()
        ->where('action', 'crm.quote.created')
        ->where('subject_id', $quote->id)
        ->exists())->toBeTrue();

    $second = phase2b2Deal($ctx);

    $this->actingAs($ctx['user'])
        ->post(route('org.deals.quotes.store', [$ctx['organization'], $second]), [])
        ->assertRedirect();

    expect(Quote::query()->where('deal_id', $second->id)->sole()->quote_number)->toBe('PEL-Q-00002');
});

test('phase 2b2 a failure after allocation burns the quote number instead of reusing it', function () {
    $ctx = phase2b2Context('admin', 'pelican-signs');
    $deal = phase2b2Deal($ctx);

    // Fail inside the create transaction, i.e. after the number has been allocated.
    QuoteRevision::creating(function (): void {
        throw new RuntimeException('revision insert exploded');
    });

    expect(fn () => app(QuoteFactoryService::class)->create(
        deal: $deal,
        createdByMembership: $ctx['membership'],
        organization: $ctx['organization'],
        quotePrefix: 'PEL-Q-',
        salesOwnerMembership: $ctx['membership'],
        actor: $ctx['user'],
    ))->toThrow(RuntimeException::class);

    expect(Quote::query()->count())->toBe(0);

    // The burned allocation must not be handed to the next quote.
    expect(app(NumberSequenceAllocator::class)->allocate(
        $ctx['organization']->fresh(),
        NumberSequenceAllocator::KEY_QUOTE,
        'PEL-Q-',
    ))->toBe('PEL-Q-00002');
});

test('phase 2b2 catalog, custom, section, and note lines can be added over http', function () {
    $ctx = phase2b2Context('admin');
    $deal = phase2b2Deal($ctx);
    [$quote, $revision] = phase2b2Draft($deal, $ctx['membership']);
    $product = phase2b2Product($ctx);

    $this->actingAs($ctx['user'])
        ->post(phase2b2RevisionRoute('org.quotes.revisions.lines.catalog', $ctx['organization'], $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'organization_product_id' => $product->id,
            'quantity' => '2',
        ])
        ->assertRedirect();

    $revision = $revision->fresh();
    expect($revision->grand_total_cents)->toBe(50_000);

    $this->actingAs($ctx['user'])
        ->post(phase2b2RevisionRoute('org.quotes.revisions.lines.custom', $ctx['organization'], $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'name' => 'Rush setup',
            'quantity' => '1',
            'unit_price' => '150.00',
            'reason' => 'Customer requested rush',
            'uom' => 'each',
        ])
        ->assertRedirect();

    $revision = $revision->fresh();

    $this->actingAs($ctx['user'])
        ->post(phase2b2RevisionRoute('org.quotes.revisions.lines.section', $ctx['organization'], $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'name' => 'Materials',
        ])
        ->assertRedirect();

    $revision = $revision->fresh();

    $this->actingAs($ctx['user'])
        ->post(phase2b2RevisionRoute('org.quotes.revisions.lines.note', $ctx['organization'], $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'name' => 'Lead time',
            'customer_description' => 'Ships in three weeks.',
        ])
        ->assertRedirect();

    $revision = $revision->fresh();
    $lines = QuoteRevisionLineItem::query()->where('quote_revision_id', $revision->id)->orderBy('position')->get();

    expect($lines->pluck('line_type')->map->value->all())
        ->toBe(['catalog', 'custom', 'section', 'note'])
        ->and($revision->grand_total_cents)->toBe(65_000)
        ->and($revision->approval_required)->toBeTrue();

    expect(AuditEvent::query()->where('action', 'crm.quote.line_added')->count())->toBe(4);

    $catalogLine = $lines->firstWhere('line_type.value', 'catalog');

    $this->actingAs($ctx['user'])
        ->post(
            phase2b2RevisionRoute('org.quotes.revisions.lines.reorder', $ctx['organization'], $quote, $revision),
            [
                'expected_lock_version' => $revision->lock_version,
                'line_ids' => $lines->pluck('id')->reverse()->values()->all(),
            ],
        )
        ->assertRedirect();

    expect($catalogLine->fresh()->position)->toBe(4);

    $revision = $revision->fresh();

    $this->actingAs($ctx['user'])
        ->delete(
            phase2b2RevisionRoute('org.quotes.revisions.lines.destroy', $ctx['organization'], $quote, $revision, $catalogLine),
            ['expected_lock_version' => $revision->lock_version],
        )
        ->assertRedirect();

    expect(QuoteRevisionLineItem::query()->where('quote_revision_id', $revision->id)->count())->toBe(3);
});

test('phase 2b2 adjustments and repricing run over http', function () {
    $ctx = phase2b2Context('admin');
    $deal = phase2b2Deal($ctx);
    [$quote, $revision] = phase2b2Draft($deal, $ctx['membership']);
    $product = phase2b2Product($ctx);

    $this->actingAs($ctx['user'])
        ->post(phase2b2RevisionRoute('org.quotes.revisions.lines.catalog', $ctx['organization'], $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'organization_product_id' => $product->id,
            'quantity' => '1',
        ])
        ->assertRedirect();

    $revision = $revision->fresh();
    $line = QuoteRevisionLineItem::query()->where('quote_revision_id', $revision->id)->sole();

    $this->actingAs($ctx['user'])
        ->post(phase2b2RevisionRoute('org.quotes.revisions.adjustments.store', $ctx['organization'], $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'adjustment_type' => 'shipping',
            'description' => 'Freight',
            'method' => 'fixed',
            'value' => '45.00',
            'is_taxable' => true,
        ])
        ->assertRedirect();

    $revision = $revision->fresh();
    $adjustment = QuoteRevisionAdjustment::query()->where('quote_revision_id', $revision->id)->sole();

    expect($adjustment->amount_cents)->toBe(4_500)
        ->and($revision->grand_total_cents)->toBe(29_500);

    $this->actingAs($ctx['user'])
        ->post(phase2b2RevisionRoute('org.quotes.revisions.adjustments.store', $ctx['organization'], $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'adjustment_type' => 'quote_discount',
            'description' => 'Loyalty',
            'method' => 'percentage',
            'value' => '10',
            'reason' => 'Repeat customer',
        ])
        ->assertRedirect();

    $revision = $revision->fresh();
    expect($revision->discount_cents)->toBe(2_500);

    $product->forceFill(['fixed_price_cents' => 30_000, 'pricing_version' => 2])->save();

    $this->actingAs($ctx['user'])
        ->post(
            phase2b2RevisionRoute('org.quotes.revisions.lines.reprice', $ctx['organization'], $quote, $revision, $line),
            ['expected_lock_version' => $revision->lock_version],
        )
        ->assertRedirect();

    expect($line->fresh()->calculated_unit_price_cents)->toBe(30_000);

    $revision = $revision->fresh();

    $this->actingAs($ctx['user'])
        ->post(
            phase2b2RevisionRoute('org.quotes.revisions.reprice-catalog', $ctx['organization'], $quote, $revision),
            ['expected_lock_version' => $revision->lock_version],
        )
        ->assertRedirect();

    $revision = $revision->fresh();

    $this->actingAs($ctx['user'])
        ->delete(
            phase2b2RevisionRoute('org.quotes.revisions.adjustments.destroy', $ctx['organization'], $quote, $revision, $adjustment),
            ['expected_lock_version' => $revision->lock_version],
        )
        ->assertRedirect();

    expect(QuoteRevisionAdjustment::query()->where('quote_revision_id', $revision->id)->count())->toBe(1);
});

test('phase 2b2 override authority gates custom lines, price overrides, and quote discounts', function () {
    $ctx = phase2b2Context('salesperson');
    $deal = phase2b2Deal($ctx);
    [$quote, $revision] = phase2b2Draft($deal, $ctx['membership']);
    $product = phase2b2Product($ctx);

    $this->actingAs($ctx['user'])
        ->post(phase2b2RevisionRoute('org.quotes.revisions.lines.custom', $ctx['organization'], $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'name' => 'Rush setup',
            'quantity' => '1',
            'unit_price' => '150.00',
            'reason' => 'Customer requested rush',
        ])
        ->assertStatus(403);

    $this->actingAs($ctx['user'])
        ->post(phase2b2RevisionRoute('org.quotes.revisions.lines.catalog', $ctx['organization'], $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'organization_product_id' => $product->id,
            'quantity' => '1',
            'override_unit_price' => '200.00',
            'override_reason' => 'Negotiated',
        ])
        ->assertStatus(403);

    $this->actingAs($ctx['user'])
        ->post(phase2b2RevisionRoute('org.quotes.revisions.adjustments.store', $ctx['organization'], $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'adjustment_type' => 'quote_discount',
            'description' => 'Loyalty',
            'method' => 'fixed',
            'value' => '10.00',
            'reason' => 'Repeat customer',
        ])
        ->assertStatus(403);

    // A plain catalog line at the calculated price needs no override authority.
    $this->actingAs($ctx['user'])
        ->post(phase2b2RevisionRoute('org.quotes.revisions.lines.catalog', $ctx['organization'], $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'organization_product_id' => $product->id,
            'quantity' => '1',
        ])
        ->assertRedirect();

    expect(QuoteRevisionLineItem::query()->where('quote_revision_id', $revision->id)->count())->toBe(1);
});

test('phase 2b2 pricing below the catalog minimum needs below-minimum approval authority', function () {
    $ctx = phase2b2Context('admin');
    attachOrgOverride($ctx['membership'], 'catalog.org_product.approve_below_minimum', PermissionEffect::Deny);

    $deal = phase2b2Deal($ctx);
    [$quote, $revision] = phase2b2Draft($deal, $ctx['membership']);
    $product = phase2b2Product($ctx, minimumPriceCents: 20_000);

    $this->actingAs($ctx['user'])
        ->post(phase2b2RevisionRoute('org.quotes.revisions.lines.catalog', $ctx['organization'], $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'organization_product_id' => $product->id,
            'quantity' => '1',
            'override_unit_price' => '150.00',
            'override_reason' => 'Negotiated',
        ])
        ->assertStatus(403);

    // At or above the minimum the same override is allowed.
    $this->actingAs($ctx['user'])
        ->post(phase2b2RevisionRoute('org.quotes.revisions.lines.catalog', $ctx['organization'], $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'organization_product_id' => $product->id,
            'quantity' => '1',
            'override_unit_price' => '220.00',
            'override_reason' => 'Negotiated',
        ])
        ->assertRedirect();

    expect(QuoteRevisionLineItem::query()->where('quote_revision_id', $revision->id)->sole()->final_unit_price_cents)
        ->toBe(22_000);
});

test('phase 2b2 a stale lock version returns 409', function () {
    $ctx = phase2b2Context('admin');
    $deal = phase2b2Deal($ctx);
    [$quote, $revision] = phase2b2Draft($deal, $ctx['membership']);
    $product = phase2b2Product($ctx);

    $this->actingAs($ctx['user'])
        ->post(phase2b2RevisionRoute('org.quotes.revisions.lines.catalog', $ctx['organization'], $quote, $revision), [
            'expected_lock_version' => $revision->lock_version + 5,
            'organization_product_id' => $product->id,
            'quantity' => '1',
        ])
        ->assertStatus(409);

    $this->actingAs($ctx['user'])
        ->patch(phase2b2RevisionRoute('org.quotes.revisions.content', $ctx['organization'], $quote, $revision), [
            'expected_lock_version' => $revision->lock_version + 5,
            'introduction' => 'Nope',
        ])
        ->assertStatus(409);

    expect(QuoteRevisionLineItem::query()->where('quote_revision_id', $revision->id)->count())->toBe(0);
});

test('phase 2b2 content updates and cloning create a new draft revision', function () {
    $ctx = phase2b2Context('admin');
    $deal = phase2b2Deal($ctx);
    [$quote, $revision] = phase2b2Draft($deal, $ctx['membership']);
    $product = phase2b2Product($ctx);

    $this->actingAs($ctx['user'])
        ->post(phase2b2RevisionRoute('org.quotes.revisions.lines.catalog', $ctx['organization'], $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'organization_product_id' => $product->id,
            'quantity' => '3',
        ])
        ->assertRedirect();

    $revision = $revision->fresh();

    $this->actingAs($ctx['user'])
        ->patch(phase2b2RevisionRoute('org.quotes.revisions.content', $ctx['organization'], $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'introduction' => 'Updated intro',
            'terms_text' => 'Net 30',
            'expiration_date' => '2027-01-15',
        ])
        ->assertRedirect();

    $revision = $revision->fresh();
    expect($revision->introduction)->toBe('Updated intro')
        ->and($revision->expiration_date->toDateString())->toBe('2027-01-15');

    $this->actingAs($ctx['user'])
        ->post(phase2b2RevisionRoute('org.quotes.revisions.clone', $ctx['organization'], $quote, $revision), [
            'expected_lock_version' => $quote->fresh()->lock_version,
        ])
        ->assertRedirect();

    $clone = QuoteRevision::query()
        ->where('quote_id', $quote->id)
        ->where('revision_number', 2)
        ->sole();

    expect($quote->fresh()->current_revision_id)->toBe($clone->id)
        ->and($clone->source_revision_id)->toBe($revision->id)
        ->and($clone->introduction)->toBe('Updated intro')
        ->and(QuoteRevisionLineItem::query()->where('quote_revision_id', $clone->id)->count())->toBe(1);
});

test('phase 2b2 the party snapshot can be edited and refreshed from the crm record', function () {
    $ctx = phase2b2Context('admin');
    $deal = phase2b2Deal($ctx);
    [$quote, $revision] = phase2b2Draft($deal, $ctx['membership']);

    $this->actingAs($ctx['user'])
        ->get(phase2b2RevisionRoute('org.quotes.revisions.party.edit', $ctx['organization'], $quote, $revision))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('quotes/PartyEdit')
            ->has('snapshot'));

    $this->actingAs($ctx['user'])
        ->patch(phase2b2RevisionRoute('org.quotes.revisions.party.update', $ctx['organization'], $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'contact_name' => 'Dana Buyer',
            'contact_email' => 'dana@example.com',
            'billing_address_json' => ['line1' => '10 Main St', 'city' => 'Tampa'],
        ])
        ->assertRedirect();

    $revision = $revision->fresh();
    expect($revision->partySnapshot->contact_name)->toBe('Dana Buyer')
        ->and($revision->partySnapshot->billing_address_json['city'])->toBe('Tampa');

    Company::query()->whereKey($deal->company_id)->update(['name' => 'Renamed Co']);

    $this->actingAs($ctx['user'])
        ->post(phase2b2RevisionRoute('org.quotes.revisions.party.refresh', $ctx['organization'], $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
        ])
        ->assertRedirect();

    expect($revision->fresh()->partySnapshot->customer_company_name)->toBe('Renamed Co');
});

test('phase 2b2 builder props omit cost keys without cost visibility and flag pending tax', function () {
    $viewer = phase2b2Context('salesperson');
    $deal = phase2b2Deal($viewer);
    [$quote, $revision] = phase2b2Draft($deal, $viewer['membership']);
    $product = phase2b2Product($viewer);

    $this->actingAs($viewer['user'])
        ->post(phase2b2RevisionRoute('org.quotes.revisions.lines.catalog', $viewer['organization'], $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'organization_product_id' => $product->id,
            'quantity' => '1',
        ])
        ->assertRedirect();

    $this->actingAs($viewer['user'])
        ->get(phase2b2RevisionRoute('org.quotes.revisions.edit', $viewer['organization'], $quote, $revision->fresh()))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('quotes/Builder')
            ->where('canViewCost', false)
            ->where('canOverridePrice', false)
            ->where('revision.tax_pending', true)
            ->where('revision.totals_are_pretax', true)
            ->where('revision.tax_message', 'Tax has not been calculated. This quote cannot be approved or sent yet.')
            ->missing('revision.cost_summary')
            ->missing('revision.lines.0.unit_cost')
            ->missing('revision.lines.0.extended_cost')
            ->missing('revision.lines.0.margin_amount')
            ->missing('revision.lines.0.margin_percent'));

    $costViewer = phase2b2Context('admin');
    $costDeal = phase2b2Deal($costViewer);
    [$costQuote, $costRevision] = phase2b2Draft($costDeal, $costViewer['membership']);
    $costProduct = phase2b2Product($costViewer);

    $this->actingAs($costViewer['user'])
        ->post(phase2b2RevisionRoute('org.quotes.revisions.lines.catalog', $costViewer['organization'], $costQuote, $costRevision), [
            'expected_lock_version' => $costRevision->lock_version,
            'organization_product_id' => $costProduct->id,
            'quantity' => '1',
        ])
        ->assertRedirect();

    $this->actingAs($costViewer['user'])
        ->get(phase2b2RevisionRoute('org.quotes.revisions.edit', $costViewer['organization'], $costQuote, $costRevision->fresh()))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canViewCost', true)
            ->has('revision.cost_summary')
            ->has('revision.lines.0.unit_cost'));
});

test('phase 2b2 quotes from another organization are not reachable', function () {
    $owner = phase2b2Context('admin');
    $deal = phase2b2Deal($owner);
    [$quote, $revision] = phase2b2Draft($deal, $owner['membership']);

    $intruder = phase2b2Context('admin');

    $this->actingAs($intruder['user'])
        ->get(route('org.quotes.show', [$intruder['organization'], $quote]))
        ->assertNotFound();

    $this->actingAs($intruder['user'])
        ->get(phase2b2RevisionRoute('org.quotes.revisions.edit', $intruder['organization'], $quote, $revision))
        ->assertNotFound();

    // Lines are bound through the revision, so a foreign line id is a 404 too.
    $this->actingAs($owner['user'])
        ->post(phase2b2RevisionRoute('org.quotes.revisions.lines.note', $owner['organization'], $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'name' => 'Foreign note',
        ])
        ->assertRedirect();

    $foreignLine = QuoteRevisionLineItem::query()->where('quote_revision_id', $revision->id)->sole();

    $intruderDeal = phase2b2Deal($intruder);
    [$intruderQuote, $intruderRevision] = phase2b2Draft($intruderDeal, $intruder['membership']);

    $this->actingAs($intruder['user'])
        ->delete(
            phase2b2RevisionRoute(
                'org.quotes.revisions.lines.destroy',
                $intruder['organization'],
                $intruderQuote,
                $intruderRevision,
                $foreignLine,
            ),
            ['expected_lock_version' => $intruderRevision->lock_version],
        )
        ->assertNotFound();
});

test('phase 2b2 the deal page lists quotes and the manual stage endpoint stays quote-controlled', function () {
    $ctx = phase2b2Context('admin');
    $deal = phase2b2Deal($ctx);
    [$quote] = phase2b2Draft($deal, $ctx['membership']);

    $this->actingAs($ctx['user'])
        ->get(route('org.deals.show', [$ctx['organization'], $deal]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('deals/Show')
            ->where('canViewQuotes', true)
            ->where('quotes.0.quote_number', $quote->quote_number)
            ->where('quotes.0.current_revision.totals_are_pretax', true));

    $this->actingAs($ctx['user'])
        ->get(route('org.deals.quotes.index', [$ctx['organization'], $deal]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('quotes/Index')
            ->has('quotes', 1));

    $this->actingAs($ctx['user'])
        ->patch(route('org.deals.stage', [$ctx['organization'], $deal]), ['stage' => DealStage::QuoteSent->value])
        ->assertStatus(409);

    expect($deal->fresh()->stage)->toBe(DealStage::Quoting);
});

test('phase 2b2 non-draft revisions cannot be opened in the builder', function () {
    $ctx = phase2b2Context('admin');
    $deal = phase2b2Deal($ctx);
    [$quote, $revision] = phase2b2Draft($deal, $ctx['membership']);

    QuoteRevision::$allowLifecycleMutation = true;

    try {
        $revision->forceFill(['status' => 'approved'])->save();
    } finally {
        QuoteRevision::$allowLifecycleMutation = false;
    }

    $this->actingAs($ctx['user'])
        ->get(phase2b2RevisionRoute('org.quotes.revisions.edit', $ctx['organization'], $quote, $revision))
        ->assertStatus(409);

    $this->actingAs($ctx['user'])
        ->get(phase2b2RevisionRoute('org.quotes.revisions.show', $ctx['organization'], $quote, $revision))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('quotes/Revision'));
});
