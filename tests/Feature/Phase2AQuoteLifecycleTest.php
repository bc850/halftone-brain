<?php

use App\Enums\QuoteLifecycleStatus;
use App\Enums\QuoteRevisionStatus;
use App\Enums\QuoteStatusTransitionSource;
use App\Http\Middleware\ResolveTenantContextFromRoute;
use App\Models\Deal;
use App\Models\Membership;
use App\Models\NumberSequence;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\ParentAccountMembership;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteStatusEvent;
use App\Models\User;
use App\Policies\QuotePolicy;
use App\Support\Quotes\IllegalQuoteTransitionException;
use App\Support\Quotes\ImmutableQuoteRevisionException;
use App\Support\Quotes\QuoteFactoryService;
use App\Support\Quotes\QuoteNumberSequenceDefinitions;
use App\Support\Quotes\QuoteNumberSequenceSynchronizer;
use App\Support\Quotes\QuoteRevisionCloner;
use App\Support\Quotes\QuoteRevisionStateMachine;
use App\Support\Quotes\QuoteRevisionTransitionService;
use App\Support\Quotes\StaleQuoteStateException;
use App\Support\Tenancy\NumberSequenceAllocator;
use App\Support\Tenancy\PermissionResolver;
use App\Support\Tenancy\TenantContext;
use Database\Factories\QuoteFactory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * @param  array{user: User, parent: ParentAccount, organization: Organization, membership: Membership, parentMembership: ParentAccountMembership|null}  $ctx
 */
function phase2aEstablishTenant(array $ctx): void
{
    TenantContext::clear();

    $resolver = app(PermissionResolver::class);

    TenantContext::establish(
        userId: $ctx['user']->id,
        parentAccountId: $ctx['parent']->id,
        organizationId: $ctx['organization']->id,
        parentMembershipId: $ctx['parentMembership']?->id,
        organizationMembershipId: $ctx['membership']->id,
        organization: $ctx['organization'],
        parentPermissions: $resolver->forParentMembership($ctx['parentMembership']),
        organizationPermissions: $resolver->forOrganizationMembership($ctx['membership']),
    );
}

/**
 * @param  array{user: User, organization: Organization, membership: Membership, parent: ParentAccount}  $ctx
 */
function phase2aDealForTenant(array $ctx, array $attributes = []): Deal
{
    return Deal::factory()->create(array_merge([
        'organization_id' => $ctx['organization']->id,
        'parent_account_id' => $ctx['parent']->id,
        'owner_id' => $ctx['user']->id,
    ], $attributes));
}

function phase2aTransition(
    Quote $quote,
    QuoteRevision $revision,
    QuoteRevisionStatus $to,
    QuoteStatusTransitionSource $source = QuoteStatusTransitionSource::User,
    ?User $actor = null,
    ?Membership $membership = null,
): QuoteRevision {
    $quote = $quote->fresh() ?? $quote;
    $revision = $revision->fresh() ?? $revision;

    return app(QuoteRevisionTransitionService::class)->transition(
        quote: $quote,
        revision: $revision,
        to: $to,
        source: $source,
        expectedQuoteLockVersion: $quote->lock_version,
        expectedRevisionLockVersion: $revision->lock_version,
        actor: $actor,
        actorMembership: $membership,
    );
}

test('phase 2a legal transitions draft to approved to sent', function () {
    $ctx = createTenantUser('salesperson');
    $deal = phase2aDealForTenant($ctx);
    $quote = QuoteFactory::createForDeal($deal, $ctx['membership']);
    $revision = $quote->currentRevision;
    expect($revision->status)->toBe(QuoteRevisionStatus::Draft);

    $revision = phase2aTransition($quote, $revision, QuoteRevisionStatus::Approved, actor: $ctx['user'], membership: $ctx['membership']);
    expect($revision->status)->toBe(QuoteRevisionStatus::Approved);

    $revision = phase2aTransition($quote, $revision, QuoteRevisionStatus::Sent, actor: $ctx['user'], membership: $ctx['membership']);
    expect($revision->status)->toBe(QuoteRevisionStatus::Sent)
        ->and($revision->sent_at)->not->toBeNull()
        ->and($quote->fresh()->lifecycle_status)->toBe(QuoteLifecycleStatus::Open);

    expect(QuoteStatusEvent::query()->where('quote_id', $quote->id)->where('to_status', QuoteRevisionStatus::Sent->value)->exists())->toBeTrue();
});

test('phase 2a pending approval can return to draft on rejection', function () {
    $ctx = createTenantUser('salesperson');
    $quote = QuoteFactory::createForDeal(phase2aDealForTenant($ctx), $ctx['membership']);
    $revision = $quote->currentRevision;

    $revision = phase2aTransition($quote, $revision, QuoteRevisionStatus::PendingApproval);
    expect(QuoteRevisionStateMachine::canTransition(QuoteRevisionStatus::PendingApproval, QuoteRevisionStatus::Draft))->toBeTrue();

    $revision = phase2aTransition($quote, $revision, QuoteRevisionStatus::Draft, QuoteStatusTransitionSource::Approval);
    expect($revision->status)->toBe(QuoteRevisionStatus::Draft);
});

test('phase 2a illegal transitions are rejected', function () {
    $ctx = createTenantUser('salesperson');
    $quote = QuoteFactory::createForDeal(phase2aDealForTenant($ctx), $ctx['membership']);
    $revision = $quote->currentRevision;

    expect(QuoteRevisionStateMachine::canTransition(QuoteRevisionStatus::Draft, QuoteRevisionStatus::Sent))->toBeFalse();

    expect(fn () => QuoteRevisionStateMachine::assertCanTransition(QuoteRevisionStatus::Draft, QuoteRevisionStatus::Sent))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => phase2aTransition($quote, $revision, QuoteRevisionStatus::Sent))
        ->toThrow(IllegalQuoteTransitionException::class);

    expect($revision->fresh()->status)->toBe(QuoteRevisionStatus::Draft);
});

test('phase 2a stale lock versions return 409', function () {
    $ctx = createTenantUser('salesperson');
    $quote = QuoteFactory::createForDeal(phase2aDealForTenant($ctx), $ctx['membership']);
    $revision = $quote->currentRevision;

    expect(fn () => app(QuoteRevisionTransitionService::class)->transition(
        quote: $quote,
        revision: $revision,
        to: QuoteRevisionStatus::Approved,
        source: QuoteStatusTransitionSource::User,
        expectedQuoteLockVersion: $quote->lock_version + 1,
        expectedRevisionLockVersion: $revision->lock_version,
        actor: $ctx['user'],
        actorMembership: $ctx['membership'],
    ))->toThrow(StaleQuoteStateException::class);

    try {
        app(QuoteRevisionTransitionService::class)->transition(
            quote: $quote,
            revision: $revision,
            to: QuoteRevisionStatus::Approved,
            source: QuoteStatusTransitionSource::User,
            expectedQuoteLockVersion: $quote->lock_version,
            expectedRevisionLockVersion: $revision->lock_version - 1,
            actor: $ctx['user'],
            actorMembership: $ctx['membership'],
        );
        expect(false)->toBeTrue();
    } catch (StaleQuoteStateException $exception) {
        expect($exception->getStatusCode())->toBe(409);
    }

    expect($revision->fresh()->status)->toBe(QuoteRevisionStatus::Draft);
});

test('phase 2a terminal accepted to accepted is idempotent', function () {
    $ctx = createTenantUser('salesperson');
    $quote = QuoteFactory::createForDeal(phase2aDealForTenant($ctx), $ctx['membership']);
    $revision = $quote->currentRevision;

    $revision = phase2aTransition($quote, $revision, QuoteRevisionStatus::Approved);
    $revision = phase2aTransition($quote, $revision, QuoteRevisionStatus::Sent);
    $revision = phase2aTransition($quote, $revision, QuoteRevisionStatus::Accepted);

    $quoteLock = $quote->fresh()->lock_version;
    $revisionLock = $revision->lock_version;
    $eventCount = QuoteStatusEvent::query()->where('quote_id', $quote->id)->count();

    $same = phase2aTransition($quote, $revision, QuoteRevisionStatus::Accepted);

    expect($same->status)->toBe(QuoteRevisionStatus::Accepted)
        ->and($same->lock_version)->toBe($revisionLock)
        ->and($quote->fresh()->lock_version)->toBe($quoteLock)
        ->and(QuoteStatusEvent::query()->where('quote_id', $quote->id)->count())->toBe($eventCount);
});

test('phase 2a transition rolls back when status event persistence fails', function () {
    $ctx = createTenantUser('salesperson');
    $quote = QuoteFactory::createForDeal(phase2aDealForTenant($ctx), $ctx['membership']);
    $revision = $quote->currentRevision;
    $revision = phase2aTransition($quote, $revision, QuoteRevisionStatus::Approved);

    $freshQuote = $quote->fresh();
    $freshRevision = $revision->fresh();
    $beforeLock = $freshRevision->lock_version;
    $beforeEvents = QuoteStatusEvent::query()->where('quote_id', $quote->id)->count();

    QuoteStatusEvent::creating(function (): void {
        throw new RuntimeException('deal sync failed');
    });

    try {
        expect(fn () => app(QuoteRevisionTransitionService::class)->transition(
            quote: $freshQuote,
            revision: $freshRevision,
            to: QuoteRevisionStatus::Sent,
            source: QuoteStatusTransitionSource::User,
            expectedQuoteLockVersion: $freshQuote->lock_version,
            expectedRevisionLockVersion: $freshRevision->lock_version,
            actor: $ctx['user'],
            actorMembership: $ctx['membership'],
        ))->toThrow(RuntimeException::class, 'deal sync failed');
    } finally {
        QuoteStatusEvent::getEventDispatcher()?->forget(
            'eloquent.creating: '.QuoteStatusEvent::class
        );
    }

    expect($revision->fresh()->status)->toBe(QuoteRevisionStatus::Approved)
        ->and($revision->fresh()->lock_version)->toBe($beforeLock)
        ->and($revision->fresh()->sent_at)->toBeNull()
        ->and(QuoteStatusEvent::query()->where('quote_id', $quote->id)->count())->toBe($beforeEvents);
});

test('phase 2a sent revision content is immutable', function () {
    $ctx = createTenantUser('salesperson');
    $quote = QuoteFactory::createForDeal(phase2aDealForTenant($ctx), $ctx['membership']);
    $revision = $quote->currentRevision;
    $revision->forceFill(['introduction' => 'Draft intro'])->save();

    $revision = phase2aTransition($quote, $revision, QuoteRevisionStatus::Approved);
    $revision = phase2aTransition($quote, $revision, QuoteRevisionStatus::Sent);

    expect(fn () => $revision->fresh()->forceFill(['introduction' => 'Changed after send'])->save())
        ->toThrow(ImmutableQuoteRevisionException::class);

    expect($revision->fresh()->introduction)->toBe('Draft intro');
});

test('phase 2a clone creates next revision number and concurrent stale clone fails', function () {
    $ctx = createTenantUser('salesperson');
    $quote = QuoteFactory::createForDeal(phase2aDealForTenant($ctx), $ctx['membership']);
    $source = $quote->currentRevision;

    $cloneA = app(QuoteRevisionCloner::class)->cloneToDraft(
        quote: $quote,
        source: $source,
        expectedQuoteLockVersion: $quote->lock_version,
        actor: $ctx['user'],
    );

    expect($cloneA->revision_number)->toBe(2)
        ->and($cloneA->status)->toBe(QuoteRevisionStatus::Draft)
        ->and($cloneA->source_revision_id)->toBe($source->id)
        ->and($quote->fresh()->current_revision_id)->toBe($cloneA->id);

    expect(fn () => app(QuoteRevisionCloner::class)->cloneToDraft(
        quote: $quote,
        source: $source,
        expectedQuoteLockVersion: $quote->lock_version,
        actor: $ctx['user'],
    ))->toThrow(StaleQuoteStateException::class);

    $cloneB = app(QuoteRevisionCloner::class)->cloneToDraft(
        quote: $quote->fresh(),
        source: $source,
        expectedQuoteLockVersion: $quote->fresh()->lock_version,
        actor: $ctx['user'],
    );

    expect($cloneB->revision_number)->toBe(3)
        ->and(QuoteRevision::query()->where('quote_id', $quote->id)->pluck('revision_number')->unique()->count())->toBe(3);
});

test('phase 2a accepted uniqueness blocks a second accepted revision', function () {
    $ctx = createTenantUser('salesperson');
    $quote = QuoteFactory::createForDeal(phase2aDealForTenant($ctx), $ctx['membership']);
    $first = $quote->currentRevision;

    $first = phase2aTransition($quote, $first, QuoteRevisionStatus::Approved);
    $first = phase2aTransition($quote, $first, QuoteRevisionStatus::Sent);

    $second = app(QuoteRevisionCloner::class)->cloneToDraft(
        quote: $quote->fresh(),
        source: $first,
        expectedQuoteLockVersion: $quote->fresh()->lock_version,
        actor: $ctx['user'],
    );
    $second = phase2aTransition($quote, $second, QuoteRevisionStatus::Approved);
    $second = phase2aTransition($quote, $second, QuoteRevisionStatus::Sent);

    phase2aTransition($quote, $first->fresh(), QuoteRevisionStatus::Accepted);

    expect(fn () => phase2aTransition($quote, $second->fresh(), QuoteRevisionStatus::Accepted))
        ->toThrow(IllegalQuoteTransitionException::class, 'already has an accepted revision');
});

test('phase 2a quote numbers come from allocator not max id', function () {
    $ctx = createTenantUser('salesperson');
    $organization = $ctx['organization'];

    NumberSequence::query()->create([
        'organization_id' => $organization->id,
        'sequence_key' => NumberSequenceAllocator::KEY_QUOTE,
        'prefix' => 'ALLOC-Q-',
        'next_number' => 42,
        'pad_length' => 5,
    ]);

    $quote = app(QuoteFactoryService::class)->create(
        deal: phase2aDealForTenant($ctx),
        createdByMembership: $ctx['membership'],
        organization: $organization,
        quotePrefix: 'ALLOC-Q-',
        padLength: 5,
        salesOwnerMembership: $ctx['membership'],
        actor: $ctx['user'],
    );

    expect($quote->quote_number)->toBe('ALLOC-Q-00042')
        ->and($quote->quote_number)->not->toBe('ALLOC-Q-'.str_pad((string) $quote->id, 5, '0', STR_PAD_LEFT))
        ->and(NumberSequence::query()
            ->where('organization_id', $organization->id)
            ->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)
            ->value('next_number'))->toBe(43);
});

test('phase 2a quote number sequence synchronizer creates missing sequences', function () {
    $pelican = Organization::factory()->create(['slug' => 'pelican-signs']);
    $brim = Organization::factory()->create(['slug' => 'brim-drinkware']);
    Organization::factory()->create(['slug' => 'other-org']);

    $db = (string) config('database.connections.'.config('database.default').'.database');
    $synchronizer = app(QuoteNumberSequenceSynchronizer::class);

    $result = $synchronizer->run(false, $db);

    expect($result['applied'])->toHaveCount(2)
        ->and(collect($result['applied'])->pluck('organization_slug')->sort()->values()->all())
        ->toBe(['brim-drinkware', 'pelican-signs']);

    expect(NumberSequence::query()
        ->where('organization_id', $pelican->id)
        ->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)
        ->where('prefix', QuoteNumberSequenceDefinitions::forOrganizationSlug('pelican-signs')['prefix'])
        ->exists())->toBeTrue()
        ->and(NumberSequence::query()
            ->where('organization_id', $brim->id)
            ->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)
            ->exists())->toBeTrue();

    $second = $synchronizer->run(false, $db);

    expect($second['applied'])->toBe([])
        ->and($second['plan']['sequences_to_create'])->toBe([])
        ->and($second['plan']['unchanged_sequences'])->toHaveCount(2);
});

test('phase 2a quote policy allows own quote and view_all sees others', function () {
    $owner = createTenantUser('salesperson');
    $peer = createTenantUser('salesperson');
    $manager = createTenantUser('sales_manager');

    $peer['membership']->update(['organization_id' => $owner['organization']->id]);
    $manager['membership']->update(['organization_id' => $owner['organization']->id]);
    $manager['organization'] = $owner['organization'];
    $manager['parent'] = $owner['parent'];

    $deal = phase2aDealForTenant($owner);
    $ownedQuote = QuoteFactory::createForDeal($deal, $owner['membership']);

    $otherDeal = Deal::factory()->create([
        'organization_id' => $owner['organization']->id,
        'parent_account_id' => $owner['parent']->id,
        'owner_id' => $peer['user']->id,
    ]);
    $otherQuote = QuoteFactory::createForDeal($otherDeal, $peer['membership']);

    phase2aEstablishTenant($owner);
    expect(Gate::forUser($owner['user'])->allows('view', $ownedQuote))->toBeTrue()
        ->and(Gate::forUser($owner['user'])->denies('view', $otherQuote))->toBeTrue();

    TenantContext::clear();
    phase2aEstablishTenant([
        'user' => $manager['user'],
        'parent' => $owner['parent'],
        'organization' => $owner['organization'],
        'membership' => $manager['membership']->fresh(['roles.permissions']),
        'parentMembership' => null,
    ]);

    expect(Gate::forUser($manager['user'])->allows('view', $ownedQuote))->toBeTrue()
        ->and(Gate::forUser($manager['user'])->allows('view', $otherQuote))->toBeTrue();

    TenantContext::clear();
});

test('phase 2a cross-organization quote binding returns 404', function () {
    $fixture = createTenantUser('admin');
    $other = createTenantUser('admin');

    $foreignQuote = QuoteFactory::createForDeal(
        Deal::factory()->create([
            'organization_id' => $other['organization']->id,
            'parent_account_id' => $other['parent']->id,
            'owner_id' => $other['user']->id,
        ]),
        $other['membership'],
    );

    Route::middleware(['web', 'auth', 'verified', ResolveTenantContextFromRoute::class])
        ->get('/o/{organization}/__quote-probe/{quote}', fn (Organization $organization, Quote $quote) => response((string) $quote->id));

    $this->actingAs($fixture['user'])
        ->get('/o/'.$fixture['organization']->slug.'/__quote-probe/'.$foreignQuote->id)
        ->assertNotFound();

    phase2aEstablishTenant($fixture);
    expect(Gate::forUser($fixture['user'])->denies('view', $foreignQuote))->toBeTrue();
    expect(app(QuotePolicy::class)->view($fixture['user'], $foreignQuote))->toBeFalse();
    TenantContext::clear();
});

test('phase 2a quote lifecycle fields cannot be mutated outside domain services', function () {
    $ctx = createTenantUser('salesperson');
    $quote = QuoteFactory::createForDeal(phase2aDealForTenant($ctx), $ctx['membership']);

    expect(fn () => $quote->forceFill(['lifecycle_status' => QuoteLifecycleStatus::Accepted])->save())
        ->toThrow(ImmutableQuoteRevisionException::class);

    expect(fn () => $quote->fresh()->currentRevision->forceFill(['status' => QuoteRevisionStatus::Approved])->save())
        ->toThrow(ImmutableQuoteRevisionException::class);
});
