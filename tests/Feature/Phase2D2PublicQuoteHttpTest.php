<?php

use App\Enums\QuoteRevisionStatus;
use App\Models\AuditEvent;
use App\Models\Quote;
use App\Models\QuoteCustomerAccessToken;
use App\Models\QuoteCustomerResponseEvent;
use App\Models\QuoteDelivery;
use App\Models\QuoteRevision;
use App\Support\Quotes\Approval\QuoteApprovalWorkflowService;
use App\Support\Quotes\Delivery\QuoteCustomerLinkPreparationService;
use App\Support\Quotes\Delivery\QuoteManualDeliveryService;
use App\Support\Quotes\Documents\QuoteDocumentGenerationService;
use App\Support\Quotes\Tax\QuoteTaxCalculationService;
use Illuminate\Support\Str;
use Tests\Support\Phase2C2Fixture;

/**
 * @return array{
 *     ctx: array<string, mixed>,
 *     quote: Quote,
 *     revision: QuoteRevision,
 *     rawToken: string,
 *     tokenId: int,
 *     deliveryId: int
 * }
 */
function phase2d2PublicSentQuote(): array
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

    expect($submitted->status)->toBe(QuoteRevisionStatus::Approved);

    $quote = $quote->fresh();
    $revision = $submitted->fresh();

    app(QuoteDocumentGenerationService::class)->generate(
        quote: $quote,
        revision: $revision,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    $prepared = app(QuoteCustomerLinkPreparationService::class)->prepare(
        quote: $quote->fresh(),
        revision: $revision->fresh(),
        actorMembership: $ctx['membership'],
        actor: $ctx['user'],
        recipientName: 'Jamie Customer',
        recipientEmail: 'jamie@example.test',
    );

    $quote = $quote->fresh();
    $revision = $revision->fresh();

    app(QuoteManualDeliveryService::class)->recordManualSend(
        quote: $quote,
        revision: $revision,
        delivery: QuoteDelivery::query()->findOrFail($prepared->deliveryId),
        token: QuoteCustomerAccessToken::query()->findOrFail($prepared->tokenId),
        expectedQuoteLockVersion: $quote->lock_version,
        expectedRevisionLockVersion: $revision->lock_version,
        recipientName: 'Jamie Customer',
        recipientEmail: 'jamie@example.test',
        confirmed: true,
        actorMembership: $ctx['membership'],
        actor: $ctx['user'],
    );

    return [
        'ctx' => $ctx,
        'quote' => $quote->fresh(),
        'revision' => $revision->fresh(),
        'rawToken' => Str::afterLast($prepared->rawCustomerUrl, '/'),
        'tokenId' => $prepared->tokenId,
        'deliveryId' => $prepared->deliveryId,
    ];
}

test('phase 2d2 public quote show records first view and sets no-store headers', function () {
    ['rawToken' => $rawToken, 'revision' => $revision] = phase2d2PublicSentQuote();

    $this->get(route('public.quotes.show', ['token' => $rawToken]))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('Quote', false)
        ->assertSee('Accept quote', false)
        ->assertDontSee('Tenant', false);

    expect($revision->fresh()->status)->toBe(QuoteRevisionStatus::Viewed)
        ->and(AuditEvent::query()->where('action', 'crm.quote.customer_first_viewed')->count())->toBe(1);
});

test('phase 2d2 public quote pdf streams without exposing storage paths', function () {
    ['rawToken' => $rawToken] = phase2d2PublicSentQuote();

    $response = $this->get(route('public.quotes.pdf', ['token' => $rawToken]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Cache-Control', 'no-store, private');

    $contentType = (string) $response->headers->get('content-type');
    $disposition = (string) $response->headers->get('content-disposition');

    expect($contentType)->toContain('application/pdf')
        ->and($disposition)->not->toContain('storage/app')
        ->and($disposition)->not->toContain('private/quotes')
        ->and((string) $response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');
});

test('phase 2d2 public accept requires typed name and terms', function () {
    ['rawToken' => $rawToken, 'revision' => $revision] = phase2d2PublicSentQuote();

    $this->from(route('public.quotes.show', ['token' => $rawToken]))
        ->post(route('public.quotes.accept', ['token' => $rawToken]), [])
        ->assertSessionHasErrors(['typed_name', 'terms_accepted']);

    $this->post(route('public.quotes.accept', ['token' => $rawToken]), [
        'typed_name' => 'Jamie Customer',
        'terms_accepted' => '1',
    ])
        ->assertOk()
        ->assertSee('acceptance has been recorded', false);

    expect($revision->fresh()->status)->toBe(QuoteRevisionStatus::Accepted)
        ->and(QuoteCustomerResponseEvent::query()->count())->toBe(1);
});

test('phase 2d2 public reject records optional reason', function () {
    ['rawToken' => $rawToken, 'revision' => $revision] = phase2d2PublicSentQuote();

    $this->post(route('public.quotes.reject', ['token' => $rawToken]), [
        'typed_name' => 'Jamie Customer',
        'rejection_reason' => 'Timing does not work',
    ])
        ->assertOk()
        ->assertSee('response has been recorded', false);

    expect($revision->fresh()->status)->toBe(QuoteRevisionStatus::Rejected)
        ->and(QuoteCustomerResponseEvent::query()->sole()->rejection_reason)->toBe('Timing does not work');
});

test('phase 2d2 public invalid expired or revoked tokens return generic 404', function () {
    $this->get(route('public.quotes.show', ['token' => 'not-a-real-token']))
        ->assertNotFound()
        ->assertSee('Quote unavailable', false)
        ->assertDontSee('2C2-Q-', false);

    ['rawToken' => $rawToken, 'tokenId' => $tokenId] = phase2d2PublicSentQuote();

    QuoteCustomerAccessToken::query()->whereKey($tokenId)->update([
        'revoked_at' => now(),
        'revoke_reason' => 'test',
    ]);

    $this->get(route('public.quotes.show', ['token' => $rawToken]))
        ->assertNotFound()
        ->assertSee('Quote unavailable', false);

    $this->get(route('public.quotes.pdf', ['token' => $rawToken]))
        ->assertNotFound();

    $this->post(route('public.quotes.accept', ['token' => $rawToken]), [
        'typed_name' => 'Jamie Customer',
        'terms_accepted' => '1',
    ])->assertNotFound();
});

test('phase 2d2 public routes do not store raw tokens in audits', function () {
    ['rawToken' => $rawToken] = phase2d2PublicSentQuote();

    $this->get(route('public.quotes.show', ['token' => $rawToken]))->assertOk();

    $encoded = AuditEvent::query()->get()->map(fn ($event) => json_encode($event->toArray()))->implode('|');

    expect($encoded)->not->toContain($rawToken);
});
