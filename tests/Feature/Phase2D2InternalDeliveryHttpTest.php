<?php

use App\Enums\MembershipStatus;
use App\Enums\QuoteDeliveryStatus;
use App\Enums\QuoteDocumentGenerationStatus;
use App\Enums\QuoteRevisionStatus;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Quote;
use App\Models\QuoteCustomerAccessToken;
use App\Models\QuoteCustomerResponseEvent;
use App\Models\QuoteDelivery;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionDocument;
use App\Models\Role;
use App\Models\User;
use App\Support\Quotes\Approval\QuoteApprovalWorkflowService;
use App\Support\Quotes\Tax\QuoteTaxCalculationService;
use App\Support\Tenancy\RoleAssigner;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Phase2C2Fixture;

beforeEach(function (): void {
    $this->withoutVite();
});

/**
 * @return array{
 *     ctx: array<string, mixed>,
 *     quote: Quote,
 *     revision: QuoteRevision
 * }
 */
function phase2d2InternalApprovedQuote(): array
{
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision, 'organizationCompany' => $company] =
        Phase2C2Fixture::draftQuote(lineUnitPriceCents: 100_000);

    Phase2C2Fixture::makeCustomerEstablished($company);

    $revision->forceFill([
        'terms_text' => 'Net 30. Payment due upon receipt of invoice.',
        'expiration_date' => now()->addDays(21)->toDateString(),
        'introduction' => 'Thank you for the opportunity to quote.',
        'issue_date' => now()->toDateString(),
    ])->save();

    Phase2C2Fixture::taxProfile($ctx);
    $rate = Phase2C2Fixture::taxRate($ctx);

    app(QuoteTaxCalculationService::class)->calculate(
        quote: $quote->fresh(),
        revision: $revision->fresh(),
        expectedLockVersion: $revision->fresh()->lock_version,
        organizationTaxRateId: $rate->id,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    $quote = $quote->fresh();
    $revision = $revision->fresh();

    $submitted = app(QuoteApprovalWorkflowService::class)->submitForApproval(
        quote: $quote,
        revision: $revision,
        expectedQuoteLockVersion: $quote->lock_version,
        expectedRevisionLockVersion: $revision->lock_version,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    return [
        'ctx' => $ctx,
        'quote' => $quote->fresh(),
        'revision' => $submitted->fresh(),
    ];
}

/**
 * @param  array<string, mixed>  $ctx
 */
function phase2d2InternalRoute(string $name, array $ctx, mixed ...$params): string
{
    return route($name, [$ctx['organization'], ...$params]);
}

test('phase 2d2 internal generate document and delivery panel render', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = phase2d2InternalApprovedQuote();

    $this->actingAs($ctx['user'])
        ->post(phase2d2InternalRoute('org.quotes.revisions.documents.generate', $ctx, $quote, $revision))
        ->assertRedirect();

    $document = QuoteRevisionDocument::query()->sole();

    expect($document->generation_status)->toBe(QuoteDocumentGenerationStatus::Generated)
        ->and($revision->fresh()->current_document_id)->toBe($document->id);

    $this->actingAs($ctx['user'])
        ->get(phase2d2InternalRoute('org.quotes.revisions.delivery', $ctx, $quote, $revision->fresh()))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quotes/DeliveryHistory')
            ->where('delivery.can_send', true)
            ->where('delivery.current_document.id', $document->id)
            ->where('quote.can_generate_document', true)
            ->where('quote.can_send', true));

    $this->actingAs($ctx['user'])
        ->get(phase2d2InternalRoute('org.quotes.revisions.documents.preview', $ctx, $quote, $revision->fresh(), $document))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8');

    $this->actingAs($ctx['user'])
        ->get(phase2d2InternalRoute('org.quotes.revisions.documents.download', $ctx, $quote, $revision->fresh(), $document))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('phase 2d2 internal prepare link returns one-time customer url without persisting raw token', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = phase2d2InternalApprovedQuote();

    $this->actingAs($ctx['user'])
        ->post(phase2d2InternalRoute('org.quotes.revisions.documents.generate', $ctx, $quote, $revision))
        ->assertRedirect();

    $response = $this->actingAs($ctx['user'])
        ->post(phase2d2InternalRoute('org.quotes.revisions.customer-link.prepare', $ctx, $quote, $revision->fresh()), [
            'recipient_name' => 'Jamie Customer',
            'recipient_email' => 'jamie@example.test',
        ]);

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quotes/CustomerLinkReady')
            ->has('customer_url')
            ->where('customer_url', fn ($url) => is_string($url) && str_contains($url, '/customer/quotes/')));

    $props = $response->original->getData()['page']['props'] ?? null;
    $customerUrl = is_array($props) ? ($props['customer_url'] ?? null) : null;
    expect($customerUrl)->toBeString();

    $rawToken = Str::afterLast((string) $customerUrl, '/');
    $token = QuoteCustomerAccessToken::query()->sole();

    expect($token->token_hash)->not->toBe($rawToken)
        ->and(json_encode($token->toArray()))->not->toContain($rawToken);

    $audit = AuditEvent::query()->where('action', 'crm.quote.customer_link_prepared')->sole();
    expect(json_encode($audit->after_json))->not->toContain($rawToken);

    $this->actingAs($ctx['user'])
        ->get(phase2d2InternalRoute('org.quotes.revisions.delivery', $ctx, $quote, $revision->fresh()))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quotes/DeliveryHistory')
            ->missing('customer_url'));
});

test('phase 2d2 internal record manual send and employee response', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = phase2d2InternalApprovedQuote();

    $this->actingAs($ctx['user'])
        ->post(phase2d2InternalRoute('org.quotes.revisions.documents.generate', $ctx, $quote, $revision))
        ->assertRedirect();

    $prepare = $this->actingAs($ctx['user'])
        ->post(phase2d2InternalRoute('org.quotes.revisions.customer-link.prepare', $ctx, $quote, $revision->fresh()), [
            'recipient_name' => 'Jamie Customer',
            'recipient_email' => 'jamie@example.test',
        ])
        ->assertOk();

    $token = QuoteCustomerAccessToken::query()->sole();
    $delivery = QuoteDelivery::query()->sole();
    $quote = $quote->fresh();
    $revision = $revision->fresh();

    $this->actingAs($ctx['user'])
        ->post(phase2d2InternalRoute('org.quotes.revisions.deliveries.record-manual', $ctx, $quote, $revision, $delivery), [
            'expected_lock_version' => $revision->lock_version,
            'expected_quote_lock_version' => $quote->lock_version,
            'quote_customer_access_token_id' => $token->id,
            'recipient_name' => 'Jamie Customer',
            'recipient_email' => 'jamie@example.test',
            'confirmed' => '1',
            'external_reference' => 'outlook-1',
        ])
        ->assertRedirect();

    expect($revision->fresh()->status)->toBe(QuoteRevisionStatus::Sent)
        ->and($delivery->fresh()->status)->toBe(QuoteDeliveryStatus::ManuallyRecorded);

    $quote = $quote->fresh();
    $revision = $revision->fresh();

    $this->actingAs($ctx['user'])
        ->post(phase2d2InternalRoute('org.quotes.revisions.employee-responses.accept', $ctx, $quote, $revision), [
            'expected_lock_version' => $revision->lock_version,
            'expected_quote_lock_version' => $quote->lock_version,
            'quote_customer_access_token_id' => $token->id,
            'typed_name' => 'Jamie Customer',
            'terms_accepted' => '1',
            'employee_recorded_reason' => 'Customer confirmed acceptance by phone.',
        ])
        ->assertRedirect();

    expect($revision->fresh()->status)->toBe(QuoteRevisionStatus::Accepted)
        ->and(QuoteCustomerResponseEvent::query()->count())->toBe(1);

    // Silence unused prepare response in static analysis contexts.
    expect($prepare->status())->toBe(200);
});

test('phase 2d2 internal stale lock versions return 409', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = phase2d2InternalApprovedQuote();

    $this->actingAs($ctx['user'])
        ->post(phase2d2InternalRoute('org.quotes.revisions.documents.generate', $ctx, $quote, $revision))
        ->assertRedirect();

    $this->actingAs($ctx['user'])
        ->post(phase2d2InternalRoute('org.quotes.revisions.customer-link.prepare', $ctx, $quote, $revision->fresh()))
        ->assertOk();

    $token = QuoteCustomerAccessToken::query()->sole();
    $delivery = QuoteDelivery::query()->sole();
    $quote = $quote->fresh();
    $revision = $revision->fresh();

    $this->actingAs($ctx['user'])
        ->post(phase2d2InternalRoute('org.quotes.revisions.deliveries.record-manual', $ctx, $quote, $revision, $delivery), [
            'expected_lock_version' => $revision->lock_version - 1,
            'expected_quote_lock_version' => $quote->lock_version,
            'quote_customer_access_token_id' => $token->id,
            'recipient_name' => 'Jamie Customer',
            'recipient_email' => 'jamie@example.test',
            'confirmed' => '1',
        ])
        ->assertStatus(409);
});

test('phase 2d2 internal cross-org document and delivery bindings 404', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = phase2d2InternalApprovedQuote();

    $this->actingAs($ctx['user'])
        ->post(phase2d2InternalRoute('org.quotes.revisions.documents.generate', $ctx, $quote, $revision))
        ->assertRedirect();

    $document = QuoteRevisionDocument::query()->sole();

    $other = createTenantUser('owner');

    $this->actingAs($other['user'])
        ->get(route('org.quotes.revisions.documents.preview', [
            $other['organization'],
            $quote,
            $revision,
            $document,
        ]))
        ->assertNotFound();

    $this->actingAs($other['user'])
        ->post(route('org.quotes.revisions.documents.generate', [
            $other['organization'],
            $quote,
            $revision,
        ]))
        ->assertNotFound();
});

test('phase 2d2 internal revoke and regenerate replace the active token', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = phase2d2InternalApprovedQuote();

    $this->actingAs($ctx['user'])
        ->post(phase2d2InternalRoute('org.quotes.revisions.documents.generate', $ctx, $quote, $revision))
        ->assertRedirect();

    $this->actingAs($ctx['user'])
        ->post(phase2d2InternalRoute('org.quotes.revisions.customer-link.prepare', $ctx, $quote, $revision->fresh()))
        ->assertOk();

    $old = QuoteCustomerAccessToken::query()->sole();

    $this->actingAs($ctx['user'])
        ->post(phase2d2InternalRoute('org.quotes.revisions.tokens.revoke', $ctx, $quote, $revision->fresh(), $old), [
            'reason' => 'lost_link',
        ])
        ->assertRedirect();

    expect($old->fresh()->isRevoked())->toBeTrue();

    $this->actingAs($ctx['user'])
        ->post(phase2d2InternalRoute('org.quotes.revisions.tokens.regenerate', $ctx, $quote, $revision->fresh()))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quotes/CustomerLinkReady')
            ->has('customer_url'));

    expect(QuoteCustomerAccessToken::query()->whereNull('revoked_at')->count())->toBe(1)
        ->and(QuoteCustomerAccessToken::query()->whereNull('revoked_at')->sole()->id)->not->toBe($old->id);
});

test('phase 2d2 internal send permission is required to prepare a link', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = phase2d2InternalApprovedQuote();

    $this->actingAs($ctx['user'])
        ->post(phase2d2InternalRoute('org.quotes.revisions.documents.generate', $ctx, $quote, $revision))
        ->assertRedirect();

    $user = User::factory()->create();
    $membership = Membership::factory()->create([
        'organization_id' => $ctx['organization']->id,
        'user_id' => $user->id,
        'status' => MembershipStatus::Active,
    ]);
    app(RoleAssigner::class)->assignToOrganizationMembership(
        $membership,
        Role::query()->where('key', 'project_manager')->firstOrFail(),
    );

    $this->actingAs($user)
        ->post(phase2d2InternalRoute('org.quotes.revisions.customer-link.prepare', $ctx, $quote, $revision->fresh()))
        ->assertForbidden();
});
