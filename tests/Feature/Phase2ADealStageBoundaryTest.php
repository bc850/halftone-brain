<?php

use App\Enums\DealStage;
use App\Enums\QuoteRevisionStatus;
use App\Enums\QuoteStatusTransitionSource;
use App\Models\Deal;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\User;
use App\Support\Deals\DealStageService;
use App\Support\Quotes\QuoteRevisionTransitionService;
use Database\Factories\QuoteFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @param  array{user: User, organization: Organization, membership: Membership, parent: ParentAccount}  $ctx
 */
function phase2aBoundaryDeal(array $ctx, DealStage $stage = DealStage::Lead): Deal
{
    return Deal::factory()->create([
        'organization_id' => $ctx['organization']->id,
        'parent_account_id' => $ctx['parent']->id,
        'owner_id' => $ctx['user']->id,
        'stage' => $stage,
    ]);
}

test('phase 2a manual stage patch to quote-controlled stages returns 409', function () {
    $ctx = createTenantUser('salesperson');
    $deal = phase2aBoundaryDeal($ctx, DealStage::Lead);

    foreach ([DealStage::Quoting, DealStage::QuoteSent, DealStage::QuoteWon] as $stage) {
        $this->actingAs($ctx['user'])
            ->patch(route('org.deals.stage', [$ctx['organization'], $deal]), [
                'stage' => $stage->value,
            ])
            ->assertStatus(409);

        expect($deal->fresh()->stage)->toBe(DealStage::Lead);
    }

    expect(app(DealStageService::class)->isQuoteControlled(DealStage::Quoting))->toBeTrue()
        ->and(app(DealStageService::class)->isQuoteControlled(DealStage::Qualified))->toBeFalse();
});

test('phase 2a manual lead to qualified stage still works', function () {
    $ctx = createTenantUser('salesperson');
    $deal = phase2aBoundaryDeal($ctx, DealStage::Lead);

    $this->actingAs($ctx['user'])
        ->patch(route('org.deals.stage', [$ctx['organization'], $deal]), [
            'stage' => DealStage::Qualified->value,
        ])
        ->assertRedirect();

    expect($deal->fresh()->stage)->toBe(DealStage::Qualified);
});

test('phase 2a won deal cannot move backward via manual stage endpoint', function () {
    $ctx = createTenantUser('salesperson');
    $deal = phase2aBoundaryDeal($ctx, DealStage::QuoteWon);

    $this->actingAs($ctx['user'])
        ->patch(route('org.deals.stage', [$ctx['organization'], $deal]), [
            'stage' => DealStage::Lead->value,
        ])
        ->assertStatus(409);

    expect($deal->fresh()->stage)->toBe(DealStage::QuoteWon);

    expect(fn () => app(DealStageService::class)->applyManualStage($deal, DealStage::Negotiations, $ctx['user']))
        ->toThrow(HttpException::class);
});

test('phase 2a quote create moves lead deal to quoting', function () {
    $ctx = createTenantUser('salesperson');
    $deal = phase2aBoundaryDeal($ctx, DealStage::Lead);

    QuoteFactory::createForDeal($deal, $ctx['membership']);

    expect($deal->fresh()->stage)->toBe(DealStage::Quoting);
});

test('phase 2a quote create moves qualified deal to quoting', function () {
    $ctx = createTenantUser('salesperson');
    $deal = phase2aBoundaryDeal($ctx, DealStage::Qualified);

    QuoteFactory::createForDeal($deal, $ctx['membership']);

    expect($deal->fresh()->stage)->toBe(DealStage::Quoting);
});

test('phase 2a sending a quote moves deal to quote_sent', function () {
    $ctx = createTenantUser('salesperson');
    $deal = phase2aBoundaryDeal($ctx, DealStage::Lead);
    $quote = QuoteFactory::createForDeal($deal, $ctx['membership']);
    $revision = $quote->currentRevision;

    expect($deal->fresh()->stage)->toBe(DealStage::Quoting);

    app(QuoteRevisionTransitionService::class)->transition(
        quote: $quote,
        revision: $revision,
        to: QuoteRevisionStatus::Approved,
        source: QuoteStatusTransitionSource::User,
        expectedQuoteLockVersion: $quote->lock_version,
        expectedRevisionLockVersion: $revision->lock_version,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    $quote = $quote->fresh();
    $revision = $revision->fresh();

    app(QuoteRevisionTransitionService::class)->transition(
        quote: $quote,
        revision: $revision,
        to: QuoteRevisionStatus::Sent,
        source: QuoteStatusTransitionSource::User,
        expectedQuoteLockVersion: $quote->lock_version,
        expectedRevisionLockVersion: $revision->lock_version,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    );

    expect($deal->fresh()->stage)->toBe(DealStage::QuoteSent);
});

test('phase 2a accepting a quote moves deal to quote_won', function () {
    $ctx = createTenantUser('salesperson');
    $deal = phase2aBoundaryDeal($ctx, DealStage::Lead);
    $quote = QuoteFactory::createForDeal($deal, $ctx['membership']);
    $revision = $quote->currentRevision;

    foreach ([QuoteRevisionStatus::Approved, QuoteRevisionStatus::Sent, QuoteRevisionStatus::Accepted] as $to) {
        $quote = $quote->fresh();
        $revision = $revision->fresh();

        app(QuoteRevisionTransitionService::class)->transition(
            quote: $quote,
            revision: $revision,
            to: $to,
            source: QuoteStatusTransitionSource::User,
            expectedQuoteLockVersion: $quote->lock_version,
            expectedRevisionLockVersion: $revision->lock_version,
            actor: $ctx['user'],
            actorMembership: $ctx['membership'],
        );
    }

    expect($deal->fresh()->stage)->toBe(DealStage::QuoteWon)
        ->and($quote->fresh()->accepted_revision_id)->toBe($revision->fresh()->id);
});
