<?php

use App\Enums\DealStage;
use App\Enums\QuoteCustomerResponseSource;
use App\Enums\QuoteCustomerResponseType;
use App\Enums\QuoteDeliveryStatus;
use App\Enums\QuoteDocumentGenerationStatus;
use App\Enums\QuoteRevisionStatus;
use App\Models\AuditEvent;
use App\Models\Deal;
use App\Models\IntegrationOutbox;
use App\Models\OrganizationCompany;
use App\Models\Quote;
use App\Models\QuoteCustomerAccessToken;
use App\Models\QuoteCustomerResponseEvent;
use App\Models\QuoteDelivery;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionDocument;
use App\Support\Quotes\Acceptance\QuoteAcceptanceAtomicityContract;
use App\Support\Quotes\Acceptance\QuoteCustomerResponseService;
use App\Support\Quotes\Access\QuoteCustomerAccessService;
use App\Support\Quotes\Approval\QuoteApprovalWorkflowService;
use App\Support\Quotes\Delivery\QuoteCustomerLinkPreparationService;
use App\Support\Quotes\Delivery\QuoteManualDeliveryService;
use App\Support\Quotes\Documents\InvalidQuoteDocumentException;
use App\Support\Quotes\Documents\QuoteDocumentGenerationService;
use App\Support\Quotes\Documents\QuoteDompdfOptions;
use App\Support\Quotes\Documents\QuotePdfRenderer;
use App\Support\Quotes\Security\QuoteCustomerAccessTokenGenerator;
use App\Support\Quotes\Snapshots\CustomerSafeQuoteProjection;
use App\Support\Quotes\Tax\QuoteTaxCalculationService;
use App\Support\Quotes\Token\QuoteCustomerTokenLifecycleService;
use App\Support\Tenancy\PermissionResolver;
use App\Support\Tenancy\TenantContext;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Phase2C2Fixture;

/**
 * @return array{
 *     ctx: array<string, mixed>,
 *     quote: Quote,
 *     revision: QuoteRevision,
 *     organizationCompany: OrganizationCompany,
 *     deal: Deal
 * }
 */
function phase2d2ApprovedQuote(): array
{
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision, 'organizationCompany' => $company, 'deal' => $deal] =
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

    return [
        'ctx' => $ctx,
        'quote' => $quote->fresh(),
        'revision' => $submitted->fresh(),
        'organizationCompany' => $company,
        'deal' => $deal->fresh(),
    ];
}

test('phase 2d2 dompdf options disable remote php and javascript and use private dejavu fonts', function () {
    $secure = (new QuoteDompdfOptions)->secureOptions();

    expect($secure['enable_remote'])->toBeFalse()
        ->and($secure['enable_php'])->toBeFalse()
        ->and($secure['enable_javascript'])->toBeFalse()
        ->and($secure['default_font'])->toBe(QuoteDompdfOptions::DEFAULT_FONT)
        ->and(str_replace('\\', '/', $secure['font_dir']))->toContain('/private/dompdf/fonts')
        ->and(str_replace('\\', '/', $secure['temp_dir']))->toContain('/private/dompdf/temp')
        ->and(is_dir(storage_path('app/private/dompdf/fonts')))->toBeTrue()
        ->and(is_file(storage_path('app/private/dompdf/fonts/DejaVuSans.ttf')))->toBeTrue();

    $options = new Options($secure);
    expect($options->getIsRemoteEnabled())->toBeFalse()
        ->and($options->getIsPhpEnabled())->toBeFalse()
        ->and($options->getIsJavascriptEnabled())->toBeFalse();
});

test('phase 2d2 generates a customer-safe document and points the revision at it', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = phase2d2ApprovedQuote();

    $document = app(QuoteDocumentGenerationService::class)->generate(
        quote: $quote,
        revision: $revision,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    expect($document->generation_status)->toBe(QuoteDocumentGenerationStatus::Generated)
        ->and($document->private_pdf_path)->not->toBeNull()
        ->and($document->private_html_path)->not->toBeNull()
        ->and($document->content_sha256)->toHaveLength(64)
        ->and($revision->fresh()->current_document_id)->toBe($document->id)
        ->and(Storage::disk('local')->exists($document->private_pdf_path))->toBeTrue()
        ->and(Storage::disk('local')->get($document->private_pdf_path))->toStartWith('%PDF');

    $payload = $document->customer_payload_snapshot_json;
    $encoded = json_encode($payload);

    foreach (CustomerSafeQuoteProjection::forbiddenKeys() as $key) {
        expect($encoded)->not->toContain('"'.$key.'"');
    }

    expect($payload['totals']['tax_unresolved'])->toBeFalse()
        ->and($payload['totals']['customer_grand_total_final'])->toBeTrue()
        ->and($payload['totals'])->toHaveKey('tax_cents')
        ->and($payload['totals'])->toHaveKey('customer_grand_total_cents')
        ->and(AuditEvent::query()->where('action', 'crm.quote.document_generated')->count())->toBe(1);

    expect(fn () => $document->update(['failure_message' => 'nope']))
        ->toThrow(LogicException::class);
});

test('phase 2d2 failed generation retains a failed attempt without current document pointer', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = phase2d2ApprovedQuote();

    $this->mock(QuotePdfRenderer::class, function ($mock): void {
        $mock->shouldReceive('render')->andThrow(new RuntimeException('renderer exploded'));
    });

    expect(fn () => app(QuoteDocumentGenerationService::class)->generate(
        quote: $quote,
        revision: $revision,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    ))->toThrow(InvalidQuoteDocumentException::class);

    $failed = QuoteRevisionDocument::query()->sole();

    expect($failed->generation_status)->toBe(QuoteDocumentGenerationStatus::Failed)
        ->and($failed->failure_code)->toBe('generation_failed')
        ->and($failed->private_pdf_path)->toBeNull()
        ->and($revision->fresh()->current_document_id)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'crm.quote.document_generation_failed')->count())->toBe(1);
});

test('phase 2d2 prepare link stores hash only and returns raw url once', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = phase2d2ApprovedQuote();

    $document = app(QuoteDocumentGenerationService::class)->generate(
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

    expect($prepared->rawCustomerUrl)->toContain('/customer/quotes/')
        ->and($prepared->documentId)->toBe($document->id);

    $rawToken = Str::afterLast($prepared->rawCustomerUrl, '/');
    $token = QuoteCustomerAccessToken::query()->findOrFail($prepared->tokenId);
    $delivery = QuoteDelivery::query()->findOrFail($prepared->deliveryId);

    expect($token->token_hash)->toBe((new QuoteCustomerAccessTokenGenerator)->hashToken($rawToken))
        ->and($token->token_hash)->not->toBe($rawToken)
        ->and($delivery->status)->toBe(QuoteDeliveryStatus::Pending)
        ->and($revision->fresh()->status)->toBe(QuoteRevisionStatus::Approved);

    $audit = AuditEvent::query()->where('action', 'crm.quote.customer_link_prepared')->sole();
    $encoded = json_encode($audit->after_json);

    expect($encoded)->not->toContain($rawToken)
        ->and($encoded)->not->toContain('"token"');
});

test('phase 2d2 manual delivery transitions to sent and moves the deal', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision, 'deal' => $deal] = phase2d2ApprovedQuote();

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
        externalReference: 'outlook-manual-1',
    );

    expect($revision->fresh()->status)->toBe(QuoteRevisionStatus::Sent)
        ->and(QuoteDelivery::query()->findOrFail($prepared->deliveryId)->status)->toBe(QuoteDeliveryStatus::ManuallyRecorded)
        ->and($deal->fresh()->stage)->toBe(DealStage::QuoteSent)
        ->and(AuditEvent::query()->where('action', 'crm.quote.manual_delivery_recorded')->count())->toBe(1);
});

test('phase 2d2 first view is idempotent for sent to viewed', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = phase2d2ApprovedQuote();

    app(QuoteDocumentGenerationService::class)->generate($quote, $revision, $ctx['user'], $ctx['membership']);
    $prepared = app(QuoteCustomerLinkPreparationService::class)->prepare(
        $quote->fresh(),
        $revision->fresh(),
        $ctx['membership'],
        $ctx['user'],
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

    $rawToken = Str::afterLast($prepared->rawCustomerUrl, '/');
    $access = app(QuoteCustomerAccessService::class);

    $first = $access->open($rawToken);
    expect($first['revision']->status)->toBe(QuoteRevisionStatus::Viewed)
        ->and($first['token']->view_count)->toBe(1)
        ->and(AuditEvent::query()->where('action', 'crm.quote.customer_first_viewed')->count())->toBe(1);

    $second = $access->open($rawToken);
    expect($second['revision']->status)->toBe(QuoteRevisionStatus::Viewed)
        ->and($second['token']->view_count)->toBe(2)
        ->and(AuditEvent::query()->where('action', 'crm.quote.customer_first_viewed')->count())->toBe(1);

    expect($access->resolveUsableToken('not-a-real-token'))->toBeNull();
});

test('phase 2d2 acceptance is atomic idempotent and inserts one pending outbox row', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision, 'deal' => $deal] = phase2d2ApprovedQuote();

    app(QuoteDocumentGenerationService::class)->generate($quote, $revision, $ctx['user'], $ctx['membership']);
    $prepared = app(QuoteCustomerLinkPreparationService::class)->prepare(
        $quote->fresh(),
        $revision->fresh(),
        $ctx['membership'],
        $ctx['user'],
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

    $rawToken = Str::afterLast($prepared->rawCustomerUrl, '/');
    app(QuoteCustomerAccessService::class)->open($rawToken);

    $token = QuoteCustomerAccessToken::query()->findOrFail($prepared->tokenId);
    $responses = app(QuoteCustomerResponseService::class);

    $accepted = $responses->acceptAsCustomer(
        token: $token->fresh(),
        typedName: 'Jamie Customer',
        termsAccepted: true,
    );

    expect($accepted->response)->toBe(QuoteCustomerResponseType::Accepted)
        ->and($accepted->source)->toBe(QuoteCustomerResponseSource::Customer)
        ->and($revision->fresh()->status)->toBe(QuoteRevisionStatus::Accepted)
        ->and($quote->fresh()->accepted_revision_id)->toBe($revision->id)
        ->and($deal->fresh()->stage)->toBe(DealStage::QuoteWon)
        ->and(QuoteCustomerResponseEvent::query()->count())->toBe(1)
        ->and(IntegrationOutbox::query()->count())->toBe(1)
        ->and(IntegrationOutbox::query()->sole()->status->value)->toBe('pending')
        ->and(IntegrationOutbox::query()->sole()->idempotency_key)
        ->toBe((new QuoteAcceptanceAtomicityContract)->designIdempotencyKey($revision->id))
        ->and(QuoteCustomerAccessToken::query()->whereNull('revoked_at')->count())->toBe(0);

    $again = $responses->acceptAsCustomer(
        token: $token->fresh(),
        typedName: 'Jamie Customer',
        termsAccepted: true,
    );

    expect($again->id)->toBe($accepted->id)
        ->and(QuoteCustomerResponseEvent::query()->count())->toBe(1)
        ->and(IntegrationOutbox::query()->count())->toBe(1);
});

test('phase 2d2 rejection is terminal without an accepted outbox row', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision, 'deal' => $deal] = phase2d2ApprovedQuote();

    app(QuoteDocumentGenerationService::class)->generate($quote, $revision, $ctx['user'], $ctx['membership']);
    $prepared = app(QuoteCustomerLinkPreparationService::class)->prepare(
        $quote->fresh(),
        $revision->fresh(),
        $ctx['membership'],
        $ctx['user'],
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

    $token = QuoteCustomerAccessToken::query()->findOrFail($prepared->tokenId);

    app(QuoteCustomerResponseService::class)->rejectAsCustomer(
        token: $token,
        typedName: 'Jamie Customer',
        rejectionReason: 'Budget changed',
    );

    expect($revision->fresh()->status)->toBe(QuoteRevisionStatus::Rejected)
        ->and(IntegrationOutbox::query()->count())->toBe(0)
        ->and($deal->fresh()->stage)->toBe(DealStage::QuoteLost)
        ->and(QuoteCustomerAccessToken::query()->whereNull('revoked_at')->count())->toBe(0);
});

test('phase 2d2 employee acceptance requires evidence and uses employee source', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision, 'deal' => $deal] = phase2d2ApprovedQuote();

    app(QuoteDocumentGenerationService::class)->generate($quote, $revision, $ctx['user'], $ctx['membership']);
    $prepared = app(QuoteCustomerLinkPreparationService::class)->prepare(
        $quote->fresh(),
        $revision->fresh(),
        $ctx['membership'],
        $ctx['user'],
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

    $quote = $quote->fresh();
    $revision = $revision->fresh();
    $token = QuoteCustomerAccessToken::query()->findOrFail($prepared->tokenId);

    $event = app(QuoteCustomerResponseService::class)->acceptAsEmployee(
        quote: $quote,
        revision: $revision,
        token: $token,
        expectedQuoteLockVersion: $quote->lock_version,
        expectedRevisionLockVersion: $revision->lock_version,
        typedName: 'Jamie Customer',
        termsAccepted: true,
        employeeRecordedReason: 'Customer confirmed acceptance by phone on 2026-07-28.',
        employeeMembership: $ctx['membership'],
        employeeUser: $ctx['user'],
    );

    expect($event->source)->toBe(QuoteCustomerResponseSource::Employee)
        ->and($revision->fresh()->status)->toBe(QuoteRevisionStatus::Accepted)
        ->and($deal->fresh()->stage)->toBe(DealStage::QuoteWon)
        ->and(IntegrationOutbox::query()->count())->toBe(1);
});

test('phase 2d2 token revoke and regenerate replace the active token', function () {
    ['ctx' => $ctx, 'quote' => $quote, 'revision' => $revision] = phase2d2ApprovedQuote();

    app(QuoteDocumentGenerationService::class)->generate($quote, $revision, $ctx['user'], $ctx['membership']);
    $prepared = app(QuoteCustomerLinkPreparationService::class)->prepare(
        $quote->fresh(),
        $revision->fresh(),
        $ctx['membership'],
        $ctx['user'],
    );

    $lifecycle = app(QuoteCustomerTokenLifecycleService::class);
    $old = QuoteCustomerAccessToken::query()->findOrFail($prepared->tokenId);

    $lifecycle->revoke($old, 'lost_link', $ctx['user'], $ctx['membership']);
    expect($old->fresh()->isRevoked())->toBeTrue();

    $regenerated = $lifecycle->regenerate(
        quote: $quote->fresh(),
        revision: $revision->fresh(),
        actorMembership: $ctx['membership'],
        actor: $ctx['user'],
    );

    expect($regenerated->tokenId)->not->toBe($prepared->tokenId)
        ->and(QuoteCustomerAccessToken::query()->whereNull('revoked_at')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'crm.quote.customer_token_regenerated')->count())->toBe(1);
});

test('phase 2d2 quote policy gates send record and generateDocument', function () {
    ['ctx' => $ctx, 'quote' => $quote] = phase2d2ApprovedQuote();

    Phase2C2Fixture::establishTenant($ctx);

    expect($ctx['user']->can('generateDocument', $quote))->toBeTrue()
        ->and($ctx['user']->can('send', $quote))->toBeTrue()
        ->and($ctx['user']->can('recordCustomerResponse', $quote))->toBeTrue();

    $salesperson = createTenantUser('salesperson');
    TenantContext::clear();
    $resolver = app(PermissionResolver::class);
    TenantContext::establish(
        userId: $salesperson['user']->id,
        parentAccountId: $salesperson['parent']->id,
        organizationId: $salesperson['organization']->id,
        parentMembershipId: $salesperson['parentMembership']?->id,
        organizationMembershipId: $salesperson['membership']->id,
        organization: $salesperson['organization'],
        parentPermissions: $resolver->forParentMembership($salesperson['parentMembership']),
        organizationPermissions: $resolver->forOrganizationMembership($salesperson['membership']),
    );

    // Different org — must fail closed.
    expect($salesperson['user']->can('send', $quote))->toBeFalse()
        ->and($salesperson['user']->can('recordCustomerResponse', $quote))->toBeFalse()
        ->and($salesperson['user']->can('generateDocument', $quote))->toBeFalse();
});
