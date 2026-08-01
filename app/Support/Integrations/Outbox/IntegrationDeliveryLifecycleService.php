<?php

namespace App\Support\Integrations\Outbox;

use App\Enums\IntegrationOutboxDeliveryStatus;
use App\Models\IntegrationOutboxDelivery;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\User;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Authorization-ready service boundaries for replay/abandon (no admin UI yet).
 */
final class IntegrationDeliveryLifecycleService
{
    public function __construct(
        private Auditor $auditor,
        private IntegrationErrorSanitizer $sanitizer,
    ) {}

    /**
     * Replay failed, dead, or blocked_configuration deliveries. Never succeeded.
     */
    public function replay(
        IntegrationOutboxDelivery $delivery,
        ?User $actor = null,
        bool $resetAttempts = false,
    ): IntegrationOutboxDelivery {
        return DB::transaction(function () use ($delivery, $actor, $resetAttempts): IntegrationOutboxDelivery {
            /** @var IntegrationOutboxDelivery $locked */
            $locked = IntegrationOutboxDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            $replayable = [
                IntegrationOutboxDeliveryStatus::Failed,
                IntegrationOutboxDeliveryStatus::Dead,
                IntegrationOutboxDeliveryStatus::BlockedConfiguration,
            ];

            if (! in_array($locked->status, $replayable, true)) {
                throw new RuntimeException(
                    "Delivery [{$locked->id}] status [{$locked->status->value}] is not replayable.",
                );
            }

            $before = [
                'status' => $locked->status->value,
                'attempt_count' => $locked->attempt_count,
                'last_error_code' => $locked->last_error_code,
                'last_error_message' => $locked->last_error_message,
                'blocked_at' => $locked->blocked_at?->toIso8601String(),
            ];

            $locked->forceFill([
                'status' => IntegrationOutboxDeliveryStatus::Pending,
                'available_at' => now(),
                'locked_at' => null,
                'locked_by_worker' => null,
                'blocked_at' => null,
                'abandoned_at' => null,
                'attempt_count' => $resetAttempts ? 0 : $locked->attempt_count,
            ])->save();

            $this->appendAudit(
                $locked,
                'integrations.outbox_delivery.replayed',
                $actor,
                $before,
                [
                    'status' => $locked->status->value,
                    'attempt_count' => $locked->attempt_count,
                    'reset_attempts' => $resetAttempts,
                    'prior_attempt_count' => $before['attempt_count'],
                    'prior_status' => $before['status'],
                ],
            );

            return $locked->refresh();
        });
    }

    /**
     * Deliberately abandon an eligible non-terminal-success delivery.
     */
    public function abandon(
        IntegrationOutboxDelivery $delivery,
        string $reason,
        ?User $actor = null,
    ): IntegrationOutboxDelivery {
        $reason = $this->sanitizer->message($reason) ?? 'abandoned';

        return DB::transaction(function () use ($delivery, $reason, $actor): IntegrationOutboxDelivery {
            /** @var IntegrationOutboxDelivery $locked */
            $locked = IntegrationOutboxDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            $abandonable = [
                IntegrationOutboxDeliveryStatus::Pending,
                IntegrationOutboxDeliveryStatus::Retrying,
                IntegrationOutboxDeliveryStatus::Failed,
                IntegrationOutboxDeliveryStatus::Dead,
                IntegrationOutboxDeliveryStatus::BlockedConfiguration,
            ];

            if (! in_array($locked->status, $abandonable, true)) {
                throw new RuntimeException(
                    "Delivery [{$locked->id}] status [{$locked->status->value}] cannot be abandoned.",
                );
            }

            $before = [
                'status' => $locked->status->value,
                'attempt_count' => $locked->attempt_count,
            ];

            $locked->forceFill([
                'status' => IntegrationOutboxDeliveryStatus::Abandoned,
                'abandoned_at' => now(),
                'locked_at' => null,
                'locked_by_worker' => null,
                'last_error_code' => $this->sanitizer->code('abandoned'),
                'last_error_message' => $reason,
            ])->save();

            $this->appendAudit(
                $locked,
                'integrations.outbox_delivery.abandoned',
                $actor,
                $before,
                [
                    'status' => $locked->status->value,
                    'reason' => $reason,
                ],
            );

            return $locked->refresh();
        });
    }

    /**
     * Release blocked_configuration deliveries for a consumer after config is supplied.
     *
     * @return int Number of deliveries released to pending
     */
    public function releaseBlockedConfiguration(
        Organization $organization,
        string $consumerKey,
        ?User $actor = null,
    ): int {
        if ($consumerKey === '') {
            throw new InvalidArgumentException('Consumer key is required.');
        }

        return DB::transaction(function () use ($organization, $consumerKey, $actor): int {
            $rows = IntegrationOutboxDelivery::query()
                ->where('organization_id', $organization->id)
                ->where('consumer_key', $consumerKey)
                ->where('status', IntegrationOutboxDeliveryStatus::BlockedConfiguration->value)
                ->lockForUpdate()
                ->get();

            $count = 0;

            foreach ($rows as $row) {
                /** @var IntegrationOutboxDelivery $row */
                $before = [
                    'status' => $row->status->value,
                    'attempt_count' => $row->attempt_count,
                    'last_error_code' => $row->last_error_code,
                ];

                $row->forceFill([
                    'status' => IntegrationOutboxDeliveryStatus::Pending,
                    'available_at' => now(),
                    'blocked_at' => null,
                    'locked_at' => null,
                    'locked_by_worker' => null,
                ])->save();

                $this->appendAudit(
                    $row,
                    'integrations.outbox_delivery.configuration_released',
                    $actor,
                    $before,
                    [
                        'status' => $row->status->value,
                        'consumer_key' => $consumerKey,
                    ],
                );

                $count++;
            }

            return $count;
        });
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function appendAudit(
        IntegrationOutboxDelivery $delivery,
        string $action,
        ?User $actor,
        array $before,
        array $after,
    ): void {
        $parent = ParentAccount::query()->findOrFail($delivery->parent_account_id);
        $organization = Organization::query()->findOrFail($delivery->organization_id);

        $this->auditor->append(
            parentAccount: $parent,
            action: $action,
            subjectType: IntegrationOutboxDelivery::class,
            subjectId: $delivery->id,
            organization: $organization,
            actor: $actor,
            before: $before,
            after: $after,
            correlationId: $delivery->correlation_id,
        );
    }
}
