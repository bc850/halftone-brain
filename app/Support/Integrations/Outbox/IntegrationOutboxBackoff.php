<?php

namespace App\Support\Integrations\Outbox;

/**
 * Bounded exponential backoff with injectable jitter for deterministic tests.
 */
final class IntegrationOutboxBackoff
{
    public function __construct(
        private int $baseSeconds = 5,
        private int $maxSeconds = 3600,
        private int $maxAttempts = 12,
        /** @var callable(): int */
        private $jitterPercentResolver = null,
    ) {
        if ($this->jitterPercentResolver === null) {
            $this->jitterPercentResolver = static fn (): int => random_int(0, 100);
        }
    }

    public static function forDeliveries(): self
    {
        return new self(
            baseSeconds: (int) config('integrations.deliveries.backoff_base_seconds', 5),
            maxSeconds: (int) config('integrations.deliveries.backoff_max_seconds', 3600),
            maxAttempts: (int) config('integrations.deliveries.max_attempts', 12),
        );
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * Delay in whole seconds after the given 1-based attempt number has failed.
     */
    public function delaySecondsAfterAttempt(int $attemptCount): int
    {
        $attempt = max(1, $attemptCount);
        $exponent = min(20, $attempt - 1);
        $raw = $this->baseSeconds * (2 ** $exponent);
        $capped = min($this->maxSeconds, $raw);

        $jitterPercent = (int) ($this->jitterPercentResolver)();
        $jitterPercent = max(0, min(100, $jitterPercent));

        // Full jitter in the range [50%, 150%] of capped delay, integer math only.
        $scaled = intdiv($capped * (50 + intdiv($jitterPercent, 2)), 100);

        return max(1, min($this->maxSeconds, $scaled));
    }

    public function isExhausted(int $attemptCount): bool
    {
        return $attemptCount >= $this->maxAttempts;
    }
}
