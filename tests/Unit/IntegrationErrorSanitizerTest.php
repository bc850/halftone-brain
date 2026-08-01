<?php

use App\Support\Audit\AuditRedactor;
use App\Support\Integrations\Outbox\IntegrationErrorSanitizer;
use Tests\TestCase;

uses(TestCase::class);

test('integration error sanitizer redacts secrets and bounds messages', function () {
    $sanitizer = new IntegrationErrorSanitizer(new AuditRedactor);

    $message = $sanitizer->message(
        'Authorization: Bearer super-secret-token-value failed for https://user:pass@example.com/path '.str_repeat('x', 600),
    );

    expect($message)->not->toContain('super-secret-token-value')
        ->and($message)->not->toContain('user:pass@')
        ->and($message)->toContain('[REDACTED]')
        ->and(strlen((string) $message))->toBeLessThanOrEqual(500);

    expect($sanitizer->code('http 429!!'))->toBe('http_429__');
});

test('provider reference allowlist drops forbidden keys and bodies', function () {
    $sanitizer = new IntegrationErrorSanitizer(new AuditRedactor);

    $ref = $sanitizer->providerReference([
        'provider' => 'diagnostic',
        'remote_id' => '123',
        'authorization' => 'Bearer abc',
        'response_body' => '{"token":"secret"}',
        'raw' => ['nested' => true],
        'resource_type' => 'probe',
        'idempotency_key' => 'abc',
        'remote_board_id' => 'board_1',
        'remote_url' => 'https://monday.test/boards/1/pulses/2',
        'provider_request_id' => 'req_1',
        'idempotency_replayed' => true,
        'api_version' => '2026-07',
    ]);

    expect($ref)->toBe([
        'provider' => 'diagnostic',
        'remote_id' => '123',
        'resource_type' => 'probe',
        'idempotency_key' => 'abc',
        'remote_board_id' => 'board_1',
        'remote_url' => 'https://monday.test/boards/1/pulses/2',
        'provider_request_id' => 'req_1',
        'idempotency_replayed' => true,
        'api_version' => '2026-07',
    ]);
});
