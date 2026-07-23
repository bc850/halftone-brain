<?php

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Support\Audit\Auditor;
use App\Support\Audit\AuditRedactor;

test('recursive and case-insensitive redaction replaces sensitive keys', function () {
    $redactor = app(AuditRedactor::class);

    $payload = [
        'Password' => 'secret-value',
        'nested' => [
            'API_KEY' => 'abc',
            'ok' => 'visible',
            'deeper' => [
                'Refresh-Token' => 'tok',
                'two_factor_secret' => 'otp',
            ],
        ],
        'Authorization' => 'Bearer xyz',
    ];

    $redacted = $redactor->redact($payload);

    expect($redacted['Password'])->toBe('[REDACTED]')
        ->and($redacted['Authorization'])->toBe('[REDACTED]')
        ->and($redacted['nested']['API_KEY'])->toBe('[REDACTED]')
        ->and($redacted['nested']['ok'])->toBe('visible')
        ->and($redacted['nested']['deeper']['Refresh-Token'])->toBe('[REDACTED]')
        ->and($redacted['nested']['deeper']['two_factor_secret'])->toBe('[REDACTED]');
});

test('auditor appends redacted events', function () {
    $parent = ParentAccount::factory()->create();
    $organization = Organization::factory()->create([
        'parent_account_id' => $parent->id,
    ]);

    $event = app(Auditor::class)->append(
        parentAccount: $parent,
        action: 'test.action',
        subjectType: Organization::class,
        subjectId: $organization->id,
        organization: $organization,
        actor: null,
        before: null,
        after: [
            'checkpoint' => 'test',
            'password' => 'should-not-persist',
            'counts' => ['organizations' => 1],
        ],
        ip: null,
        userAgent: null,
        correlationId: '11111111-1111-1111-1111-111111111111',
    );

    expect($event->actor_user_id)->toBeNull()
        ->and($event->ip)->toBeNull()
        ->and($event->user_agent)->toBeNull()
        ->and($event->after_json['password'])->toBe('[REDACTED]')
        ->and($event->after_json['checkpoint'])->toBe('test')
        ->and($event->after_json['counts']['organizations'])->toBe(1);
});

test('audit update attempts fail', function () {
    $event = AuditEvent::factory()->create();

    $event->action = 'mutated';
    $event->save();
})->throws(LogicException::class);

test('audit delete attempts fail', function () {
    $event = AuditEvent::factory()->create();

    $event->delete();
})->throws(LogicException::class);

test('audit_events has no updated_at column usage', function () {
    expect(AuditEvent::UPDATED_AT)->toBeNull();

    $event = AuditEvent::factory()->create();
    expect($event->getAttributes())->not->toHaveKey('updated_at');
});
