<?php

namespace App\Support\Integrations\Outbox;

use App\Enums\IntegrationOutboxDeliveryStatus;
use App\Models\IntegrationOutboxDelivery;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\User;
use App\Support\Audit\Auditor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Authorization-ready service boundaries for delivery replay and abandon.
 */
final class IntegrationDeliveryLifecycleService
{
    public const AUDIT_REPLAYED = 'integrations.outbox.delivery_replayed';

    public const AUDIT_ABANDONED = 'integrations.outbox.delivery_abandoned';

    public function __construct(
        private Auditor $auditor,
        private IntegrationErrorSanitizer $sanitizer,
    ) {}

    /**
     * Replay failed, dead, or blocked_configuration deliveries. Never succeeded.
     */
    public function replay(
        IntegrationOutboxDelivery $delivery,
        string $reason,
        ?User $actor = null,
        bool $resetAttempts = false,
        ?string $expectedStatus = null,
    ): IntegrationOutboxDelivery {
        $reason = $this->requireReason($reason);

        return DB::transaction(function () use ($delivery, $reason, $actor, $resetAttempts, $expectedStatus): IntegrationOutboxDelivery {
            /** @var IntegrationOutboxDelivery $locked */
            $locked = IntegrationOutboxDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            if ($expectedStatus !== null && $locked->status->value !== $expectedStatus) {
                throw new StaleIntegrationDeliveryStateException(
                    'Delivery status changed before replay could be applied.',
                );
            }

            $replayable = [
                IntegrationOutboxDeliveryStatus::Failed,
                IntegrationOutboxDeliveryStatus::Dead,
                IntegrationOutboxDeliveryStatus::BlockedConfiguration,
            ];

            if (! in_array($locked->status, $replayable, true)) {
                throw new StaleIntegrationDeliveryStateException(
                    "Delivery [{$locked->id}] status [{$locked->status->value}] is not replayable.",
                );
            }

            $before = [
                'status' => $locked->status->value,
                'attempt_count' => $locked->attempt_count,
                'last_error_code' => $locked->last_error_code,
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
                self::AUDIT_REPLAYED,
                $actor,
                $before,
                [
                    'status' => $locked->status->value,
                    'attempt_count' => $locked->attempt_count,
                    'reset_attempts' => $resetAttempts,
                    'prior_attempt_count' => $before['attempt_count'],
                    'prior_status' => $before['status'],
                    'prior_error_code' => $before['last_error_code'],
                    'reason' => $reason,
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
        ?string $expectedStatus = null,
    ): IntegrationOutboxDelivery {
        $reason = $this->requireReason($reason);

        return DB::transaction(function () use ($delivery, $reason, $actor, $expectedStatus): IntegrationOutboxDelivery {
            /** @var IntegrationOutboxDelivery $locked */
            $locked = IntegrationOutboxDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            if ($expectedStatus !== null && $locked->status->value !== $expectedStatus) {
                throw new StaleIntegrationDeliveryStateException(
                    'Delivery status changed before abandon could be applied.',
                );
            }

            if ($locked->status === IntegrationOutboxDeliveryStatus::Succeeded) {
                throw new StaleIntegrationDeliveryStateException('Succeeded deliveries cannot be abandoned.');
            }

            if ($locked->status === IntegrationOutboxDeliveryStatus::Processing) {
                if (! $this->leaseIsExpired($locked)) {
                    throw new StaleIntegrationDeliveryStateException(
                        'Actively leased deliveries cannot be abandoned until the lease expires and is reclaimed.',
                    );
                }
            }

            $abandonable = [
                IntegrationOutboxDeliveryStatus::Pending,
                IntegrationOutboxDeliveryStatus::Retrying,
                IntegrationOutboxDeliveryStatus::Failed,
                IntegrationOutboxDeliveryStatus::Dead,
                IntegrationOutboxDeliveryStatus::BlockedConfiguration,
                IntegrationOutboxDeliveryStatus::Processing,
            ];

            if (! in_array($locked->status, $abandonable, true)) {
                throw new StaleIntegrationDeliveryStateException(
                    "Delivery [{$locked->id}] status [{$locked->status->value}] cannot be abandoned.",
                );
            }

            $before = [
                'status' => $locked->status->value,
                'attempt_count' => $locked->attempt_count,
                'last_error_code' => $locked->last_error_code,
            ];

            $locked->forceFill([
                'status' => IntegrationOutboxDeliveryStatus::Abandoned,
                'abandoned_at' => now(),
                'locked_at' => null,
                'locked_by_worker' => null,
                'available_at' => now(),
                'last_error_code' => $this->sanitizer->code('abandoned'),
                'last_error_message' => $reason,
            ])->save();

            $this->appendAudit(
                $locked,
                self::AUDIT_ABANDONED,
                $actor,
                $before,
                [
                    'status' => $locked->status->value,
                    'reason' => $reason,
                    'prior_status' => $before['status'],
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

    private function leaseIsExpired(IntegrationOutboxDelivery $delivery): bool
    {
        if ($delivery->locked_at === null) {
            return true;
        }

        $leaseSeconds = (int) config('integrations.deliveries.lease_seconds', 120);

        return $delivery->locked_at->lte(Carbon::now()->subSeconds($leaseSeconds));
    }

    private function requireReason(string $reason): string
    {
        $sanitized = $this->sanitizer->message($reason);

        if ($sanitized === null || strlen(trim($reason)) < 3) {
            throw new InvalidArgumentException('A short operator reason is required.');
        }

        return $sanitized;
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
