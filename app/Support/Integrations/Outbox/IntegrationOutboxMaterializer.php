<?php

namespace App\Support\Integrations\Outbox;

use App\Enums\IntegrationOutboxStatus;
use App\Models\IntegrationOutbox;
use App\Models\IntegrationOutboxDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Claims pending outbox events and materializes per-consumer delivery rows.
 */
final class IntegrationOutboxMaterializer
{
    public function __construct(
        private IntegrationConsumerRegistry $registry,
        private IntegrationClaimLock $claimLock,
        private IntegrationErrorSanitizer $sanitizer,
    ) {}

    /**
     * @return array{claimed: int, dispatched: int, dead: int, deliveries_created: int}
     */
    public function materializeBatch(?int $organizationId = null, ?int $limit = null, ?string $workerId = null): array
    {
        $limit ??= (int) config('integrations.outbox.batch_size', 50);
        $workerId ??= $this->defaultWorkerId();
        $ids = $this->claimEligibleOutboxIds($organizationId, $limit, $workerId);

        $dispatched = 0;
        $dead = 0;
        $deliveriesCreated = 0;

        foreach ($ids as $id) {
            $result = $this->materializeClaimed((int) $id);

            $dispatched += $result['dispatched'];
            $dead += $result['dead'];
            $deliveriesCreated += $result['deliveries_created'];
        }

        return [
            'claimed' => count($ids),
            'dispatched' => $dispatched,
            'dead' => $dead,
            'deliveries_created' => $deliveriesCreated,
        ];
    }

    /**
     * @return list<int>
     */
    private function claimEligibleOutboxIds(?int $organizationId, int $limit, string $workerId): array
    {
        return DB::transaction(function () use ($organizationId, $limit, $workerId): array {
            $query = IntegrationOutbox::query()
                ->whereIn('status', [
                    IntegrationOutboxStatus::Pending->value,
                    IntegrationOutboxStatus::Failed->value,
                ])
                ->where('available_at', '<=', now())
                ->orderBy('id')
                ->limit($limit);

            if ($organizationId !== null) {
                $query->where('organization_id', $organizationId);
            }

            $this->claimLock->apply($query);

            /** @var list<IntegrationOutbox> $rows */
            $rows = $query->get();
            $ids = [];

            foreach ($rows as $row) {
                $row->forceFill([
                    'status' => IntegrationOutboxStatus::Processing,
                    'locked_at' => now(),
                    'locked_by_worker' => $workerId,
                    'attempt_count' => $row->attempt_count + 1,
                ])->save();

                $ids[] = (int) $row->id;
            }

            return $ids;
        });
    }

    /**
     * @return array{dispatched: int, dead: int, deliveries_created: int}
     */
    private function materializeClaimed(int $outboxId): array
    {
        try {
            return DB::transaction(function () use ($outboxId): array {
                /** @var IntegrationOutbox $outbox */
                $outbox = IntegrationOutbox::query()->whereKey($outboxId)->lockForUpdate()->firstOrFail();

                if ($outbox->status !== IntegrationOutboxStatus::Processing) {
                    return ['dispatched' => 0, 'dead' => 0, 'deliveries_created' => 0];
                }

                if (! $this->registry->isKnownEventType($outbox->event_type)) {
                    $outbox->forceFill([
                        'status' => IntegrationOutboxStatus::Dead,
                        'locked_at' => null,
                        'locked_by_worker' => null,
                        'last_error_code' => $this->sanitizer->code('unknown_event_type'),
                        'last_error_message' => $this->sanitizer->message(
                            'Unknown outbox event type; delivery materialization refused.',
                        ),
                    ])->save();

                    return ['dispatched' => 0, 'dead' => 1, 'deliveries_created' => 0];
                }

                $handlers = $this->registry->handlersFor($outbox->event_type);
                $applicable = array_values(array_filter(
                    $handlers,
                    static fn ($handler): bool => $handler->supports($outbox->event_type, $outbox->schema_version),
                ));

                if ($handlers !== [] && $applicable === []) {
                    $outbox->forceFill([
                        'status' => IntegrationOutboxStatus::Dead,
                        'locked_at' => null,
                        'locked_by_worker' => null,
                        'last_error_code' => $this->sanitizer->code('unsupported_schema_version'),
                        'last_error_message' => $this->sanitizer->message(
                            'No registered consumer supports this event schema version.',
                        ),
                    ])->save();

                    return ['dispatched' => 0, 'dead' => 1, 'deliveries_created' => 0];
                }

                $created = 0;

                foreach ($applicable as $handler) {
                    $idempotencyKey = IntegrationDeliveryIdempotency::design(
                        (int) $outbox->id,
                        $handler->consumerKey(),
                    );

                    $existing = IntegrationOutboxDelivery::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if ($existing !== null) {
                        continue;
                    }

                    IntegrationOutboxDelivery::query()->create([
                        'parent_account_id' => $outbox->parent_account_id,
                        'organization_id' => $outbox->organization_id,
                        'integration_outbox_id' => $outbox->id,
                        'consumer_key' => $handler->consumerKey(),
                        'idempotency_key' => $idempotencyKey,
                        'available_at' => now(),
                        'correlation_id' => $outbox->correlation_id,
                    ]);

                    $created++;
                }

                $outbox->forceFill([
                    'status' => IntegrationOutboxStatus::Dispatched,
                    'dispatched_at' => now(),
                    'locked_at' => null,
                    'locked_by_worker' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ])->save();

                return ['dispatched' => 1, 'dead' => 0, 'deliveries_created' => $created];
            });
        } catch (Throwable $exception) {
            $this->markMaterializationFailed($outboxId, $exception);

            return ['dispatched' => 0, 'dead' => 0, 'deliveries_created' => 0];
        }
    }

    private function markMaterializationFailed(int $outboxId, Throwable $exception): void
    {
        DB::transaction(function () use ($outboxId, $exception): void {
            $outbox = IntegrationOutbox::query()->whereKey($outboxId)->lockForUpdate()->first();

            if ($outbox === null || $outbox->status !== IntegrationOutboxStatus::Processing) {
                return;
            }

            $maxAttempts = (int) config('integrations.outbox.max_attempts', 12);
            $exhausted = $outbox->attempt_count >= $maxAttempts;

            $outbox->forceFill([
                'status' => $exhausted ? IntegrationOutboxStatus::Dead : IntegrationOutboxStatus::Failed,
                'available_at' => $exhausted ? $outbox->available_at : Carbon::now()->addSeconds(30),
                'locked_at' => null,
                'locked_by_worker' => null,
                'last_error_code' => $this->sanitizer->code('materialization_failed'),
                'last_error_message' => $this->sanitizer->message($exception->getMessage()),
            ])->save();
        });
    }

    private function defaultWorkerId(): string
    {
        return 'outbox-materializer:'.gethostname().':'.getmypid();
    }
}
