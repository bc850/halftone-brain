<?php

use App\Models\AuditEvent;
use App\Models\NumberSequence;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\User;
use App\Support\Tenancy\PhaseZeroBootstrap;
use Illuminate\Support\Facades\DB;

test('bootstrap seeds four sequences with approved prefixes and padding', function () {
    User::factory()->create([
        'email' => 'owner@example.com',
        'email_verified_at' => now(),
    ]);

    runBootstrap(execute: true)->assertSuccessful();

    $sequences = NumberSequence::query()->orderBy('prefix')->get();
    expect($sequences)->toHaveCount(4);

    $byPrefix = $sequences->keyBy('prefix');
    expect($byPrefix->keys()->sort()->values()->all())->toBe([
        'BRIM-C-',
        'BRIM-D-',
        'PEL-C-',
        'PEL-D-',
    ]);

    foreach ($sequences as $sequence) {
        expect($sequence->pad_length)->toBe(5)
            ->and($sequence->next_number)->toBe(1)
            ->and($sequence->sequence_key)->toBeIn(['customer', 'deal']);
    }

    $pelican = Organization::query()->where('slug', 'pelican-signs')->firstOrFail();
    $brim = Organization::query()->where('slug', 'brim-drinkware')->firstOrFail();

    expect(NumberSequence::query()->where('organization_id', $pelican->id)->where('prefix', 'PEL-C-')->where('sequence_key', 'customer')->exists())->toBeTrue()
        ->and(NumberSequence::query()->where('organization_id', $pelican->id)->where('prefix', 'PEL-D-')->where('sequence_key', 'deal')->exists())->toBeTrue()
        ->and(NumberSequence::query()->where('organization_id', $brim->id)->where('prefix', 'BRIM-C-')->where('sequence_key', 'customer')->exists())->toBeTrue()
        ->and(NumberSequence::query()->where('organization_id', $brim->id)->where('prefix', 'BRIM-D-')->where('sequence_key', 'deal')->exists())->toBeTrue();
});

test('dry run creates neither sequences nor audit events', function () {
    User::factory()->create([
        'email' => 'owner@example.com',
        'email_verified_at' => now(),
    ]);

    runBootstrap()->assertSuccessful();

    expect(NumberSequence::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});

test('second execution creates no duplicate sequences or audit events', function () {
    User::factory()->create([
        'email' => 'owner@example.com',
        'email_verified_at' => now(),
    ]);

    runBootstrap(execute: true)->assertSuccessful();
    runBootstrap(execute: true)->assertSuccessful();

    expect(NumberSequence::query()->count())->toBe(4)
        ->and(AuditEvent::query()->where('action', PhaseZeroBootstrap::COMPLETION_ACTION)->count())->toBe(2);
});

test('existing matching sequence preserves next_number', function () {
    User::factory()->create([
        'email' => 'owner@example.com',
        'email_verified_at' => now(),
    ]);

    runBootstrap(execute: true)->assertSuccessful();

    $sequence = NumberSequence::query()->where('prefix', 'PEL-C-')->firstOrFail();
    $sequence->forceFill(['next_number' => 42])->save();

    runBootstrap(execute: true)->assertSuccessful();

    expect($sequence->fresh()->next_number)->toBe(42)
        ->and(NumberSequence::query()->count())->toBe(4);
});

test('conflicting prefix or padding rolls back everything', function () {
    User::factory()->create([
        'email' => 'owner@example.com',
        'email_verified_at' => now(),
    ]);

    runBootstrap(execute: true)->assertSuccessful();

    NumberSequence::query()->where('prefix', 'PEL-C-')->update([
        'prefix' => 'WRONG-C-',
    ]);

    // Reset other bootstrap artifacts would still exist; command should fail on conflict.
    // Wipe completion audits so the failure path still evaluates sequences mid-transaction.
    // Use query builder delete to bypass append-only model guards for test setup only.
    DB::table('audit_events')->delete();

    // Also remove sequences except the conflicting one to force recreate path? Conflict is on existing row with wrong prefix.
    runBootstrap(execute: true)->assertFailed();

    expect(NumberSequence::query()->where('prefix', 'WRONG-C-')->exists())->toBeTrue()
        ->and(AuditEvent::query()->count())->toBe(0);
});

test('completion audits are null actor metadata and share one correlation id', function () {
    User::factory()->create([
        'email' => 'owner@example.com',
        'email_verified_at' => now(),
    ]);

    runBootstrap(execute: true)->assertSuccessful();

    $events = AuditEvent::query()->where('action', PhaseZeroBootstrap::COMPLETION_ACTION)->get();
    expect($events)->toHaveCount(2);

    $correlationIds = $events->pluck('correlation_id')->unique()->values();
    expect($correlationIds)->toHaveCount(1);

    foreach ($events as $event) {
        expect($event->actor_user_id)->toBeNull()
            ->and($event->ip)->toBeNull()
            ->and($event->user_agent)->toBeNull()
            ->and($event->before_json)->toBeNull()
            ->and($event->after_json['checkpoint'] ?? null)->toBe('0C-3')
            ->and($event->after_json)->not->toHaveKey('password')
            ->and($event->after_json['counts'] ?? [])->toHaveKeys([
                'parent_accounts',
                'organizations',
                'memberships',
                'number_sequences',
            ]);
    }

    $parent = ParentAccount::query()->where('slug', 'halftone-brain')->firstOrFail();
    expect($events->every(fn (AuditEvent $event) => $event->parent_account_id === $parent->id))->toBeTrue();
});

test('induced failure leaves no partial sequences or audit events', function () {
    User::factory()->create([
        'email' => 'owner@example.com',
        'email_verified_at' => now(),
    ]);

    app()->instance('phaseZeroBootstrap.induceFailure', true);

    runBootstrap(execute: true)->assertFailed();

    expect(NumberSequence::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0)
        ->and(ParentAccount::query()->count())->toBe(0);
});
