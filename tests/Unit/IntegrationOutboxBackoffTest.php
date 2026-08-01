<?php

use App\Support\Integrations\Outbox\IntegrationOutboxBackoff;

test('backoff is bounded exponential with injectable jitter and max attempts', function () {
    $backoff = new IntegrationOutboxBackoff(
        baseSeconds: 5,
        maxSeconds: 3600,
        maxAttempts: 12,
        jitterPercentResolver: static fn (): int => 0,
    );

    expect($backoff->delaySecondsAfterAttempt(1))->toBe(2) // 50% of 5
        ->and($backoff->delaySecondsAfterAttempt(2))->toBe(5) // 50% of 10
        ->and($backoff->delaySecondsAfterAttempt(10))->toBeLessThanOrEqual(3600)
        ->and($backoff->isExhausted(11))->toBeFalse()
        ->and($backoff->isExhausted(12))->toBeTrue();

    $highJitter = new IntegrationOutboxBackoff(
        baseSeconds: 5,
        maxSeconds: 3600,
        maxAttempts: 12,
        jitterPercentResolver: static fn (): int => 100,
    );

    expect($highJitter->delaySecondsAfterAttempt(1))->toBe(5); // 100% of 5 via (50 + 50)
});
