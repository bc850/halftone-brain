<?php

namespace App\Support\Integrations\Outbox;

use App\Enums\IntegrationConsumerOutcome;
use App\Enums\IntegrationOutboxDeliveryStatus;
use App\Models\IntegrationOutbox;
use App\Models\IntegrationOutboxDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Claims eligible deliveries and executes handlers outside claim transactions.
 */
final class IntegrationDeliveryProcessor
{
    public function __construct(
        private IntegrationConsumerRegistry $registry,
        private IntegrationClaimLock $claimLock,
        private IntegrationErrorSanitizer $sanitizer,
        private ?IntegrationOutboxBackoff $backoff = null,
    ) {
        $this->backoff ??= IntegrationOutboxBackoff::forDeliveries();
    }

    /**
     * @return array{claimed: int, succeeded: int, retrying: int, blocked: int, failed: int, dead: int}
     */
    public function processBatch(?int $organizationId = null, ?int $limit = null, ?string $workerId = null): array
    {
        $limit ??= (int) config('integrations.deliveries.batch_size', 50);
        $workerId ??= $this->defaultWorkerId();
        $ids = $this->claimEligibleDeliveryIds($organizationId, $limit, $workerId);

        $stats = [
            'claimed' => count($ids),
            'succeeded' => 0,
            'retrying' => 0,
            'blocked' => 0,
            'failed' => 0,
            'dead' => 0,
        ];

        foreach ($ids as $id) {
            $outcome = $this->processClaimed((int) $id);

            match ($outcome) {
                'succeeded' => $stats['succeeded']++,
                'retrying' => $stats['retrying']++,
                'blocked' => $stats['blocked']++,
                'failed' => $stats['failed']++,
                'dead' => $stats['dead']++,
                default => null,
            };
        }

        return $stats;
    }

    /**
     * @return list<int>
     */
    private function claimEligibleDeliveryIds(?int $organizationId, int $limit, string $workerId): array
    {
        return DB::transaction(function () use ($organizationId, $limit, $workerId): array {
            $query = IntegrationOutboxDelivery::query()
                ->whereIn('status', [
                    IntegrationOutboxDeliveryStatus::Pending->value,
                    IntegrationOutboxDeliveryStatus::Retrying->value,
                ])
                ->where('available_at', '<=', now())
                ->orderBy('id')
                ->limit($limit);

            if ($organizationId !== null) {
                $query->where('organization_id', $organizationId);
            }

            $this->claimLock->apply($query);

            /** @var list<IntegrationOutboxDelivery> $rows */
            $rows = $query->get();
            $ids = [];

            foreach ($rows as $row) {
                $row->forceFill([
                    'status' => IntegrationOutboxDeliveryStatus::Processing,
                    'locked_at' => now(),
                    'locked_by_worker' => $workerId,
                ])->save();

                $ids[] = (int) $row->id;
            }

            return $ids;
        });
    }

    private function processClaimed(int $deliveryId): string
    {
        $delivery = IntegrationOutboxDelivery::query()->with('outbox')->find($deliveryId);

        if ($delivery === null || $delivery->status !== IntegrationOutboxDeliveryStatus::Processing) {
            return 'skipped';
        }

        /** @var IntegrationOutbox|null $outbox */
        $outbox = $delivery->outbox;

        if ($outbox === null) {
            $this->applyPermanent($delivery, 'missing_outbox', 'Delivery references a missing outbox row.');

            return 'dead';
        }

        $handler = $this->registry->handler($outbox->event_type, $delivery->consumer_key);

        if ($handler === null) {
            $this->applyPermanent($delivery, 'unknown_consumer', 'No handler is registered for this consumer key.');

            return 'dead';
        }

        try {
            $result = $handler->handle($outbox, $delivery);
        } catch (Throwable $exception) {
            $result = IntegrationConsumerResult::retryable(
                'handler_exception',
                $exception->getMessage(),
            );
        }

        return $this->persistResult($deliveryId, $result);
    }

    private function persistResult(int $deliveryId, IntegrationConsumerResult $result): string
    {
        return DB::transaction(function () use ($deliveryId, $result): string {
            /** @var IntegrationOutboxDelivery $delivery */
            $delivery = IntegrationOutboxDelivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();

            if ($delivery->status !== IntegrationOutboxDeliveryStatus::Processing) {
                return 'skipped';
            }

            return match ($result->outcome) {
                IntegrationConsumerOutcome::Succeeded => $this->markSucceeded($delivery, $result),
                IntegrationConsumerOutcome::BlockedConfiguration => $this->markBlocked($delivery, $result),
                IntegrationConsumerOutcome::PermanentFailure => $this->markPermanent($delivery, $result),
                IntegrationConsumerOutcome::RetryableFailure,
                IntegrationConsumerOutcome::Uncertain => $this->markRetryable($delivery, $result),
            };
        });
    }

    private function markSucceeded(IntegrationOutboxDelivery $delivery, IntegrationConsumerResult $result): string
    {
        $delivery->forceFill([
            'status' => IntegrationOutboxDeliveryStatus::Succeeded,
            'succeeded_at' => now(),
            'blocked_at' => null,
            'locked_at' => null,
            'locked_by_worker' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'provider_reference_json' => $this->sanitizer->providerReference($result->providerReference),
            'attempt_count' => $delivery->attempt_count + 1,
        ])->save();

        return 'succeeded';
    }

    private function markBlocked(IntegrationOutboxDelivery $delivery, IntegrationConsumerResult $result): string
    {
        // Configuration absence must not consume retry budget.
        $delivery->forceFill([
            'status' => IntegrationOutboxDeliveryStatus::BlockedConfiguration,
            'blocked_at' => now(),
            'locked_at' => null,
            'locked_by_worker' => null,
            'last_error_code' => $this->sanitizer->code($result->errorCode),
            'last_error_message' => $this->sanitizer->message($result->errorMessage),
            'provider_reference_json' => null,
        ])->save();

        return 'blocked';
    }

    private function markPermanent(IntegrationOutboxDelivery $delivery, IntegrationConsumerResult $result): string
    {
        $delivery->forceFill([
            'status' => IntegrationOutboxDeliveryStatus::Failed,
            'locked_at' => null,
            'locked_by_worker' => null,
            'last_error_code' => $this->sanitizer->code($result->errorCode),
            'last_error_message' => $this->sanitizer->message($result->errorMessage),
            'attempt_count' => $delivery->attempt_count + 1,
            'provider_reference_json' => null,
        ])->save();

        return 'failed';
    }

    private function markRetryable(IntegrationOutboxDelivery $delivery, IntegrationConsumerResult $result): string
    {
        $attempts = $delivery->attempt_count + 1;

        if ($this->backoff->isExhausted($attempts)) {
            $delivery->forceFill([
                'status' => IntegrationOutboxDeliveryStatus::Dead,
                'attempt_count' => $attempts,
                'locked_at' => null,
                'locked_by_worker' => null,
                'last_error_code' => $this->sanitizer->code($result->errorCode ?? 'max_attempts_exhausted'),
                'last_error_message' => $this->sanitizer->message(
                    ($result->errorMessage ?? 'Retryable failure').' (max attempts exhausted)',
                ),
            ])->save();

            return 'dead';
        }

        $delay = $this->backoff->delaySecondsAfterAttempt($attempts);

        $delivery->forceFill([
            'status' => IntegrationOutboxDeliveryStatus::Retrying,
            'attempt_count' => $attempts,
            'available_at' => Carbon::now()->addSeconds($delay),
            'locked_at' => null,
            'locked_by_worker' => null,
            'last_error_code' => $this->sanitizer->code($result->errorCode),
            'last_error_message' => $this->sanitizer->message($result->errorMessage),
        ])->save();

        return 'retrying';
    }

    private function applyPermanent(IntegrationOutboxDelivery $delivery, string $code, string $message): void
    {
        DB::transaction(function () use ($delivery, $code, $message): void {
            $locked = IntegrationOutboxDelivery::query()->whereKey($delivery->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== IntegrationOutboxDeliveryStatus::Processing) {
                return;
            }

            $locked->forceFill([
                'status' => IntegrationOutboxDeliveryStatus::Dead,
                'locked_at' => null,
                'locked_by_worker' => null,
                'attempt_count' => $locked->attempt_count + 1,
                'last_error_code' => $this->sanitizer->code($code),
                'last_error_message' => $this->sanitizer->message($message),
            ])->save();
        });
    }

    private function defaultWorkerId(): string
    {
        return 'delivery-processor:'.gethostname().':'.getmypid();
    }
}
