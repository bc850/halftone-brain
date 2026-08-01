<?php

namespace App\Support\Integrations\Outbox;

use App\Enums\IntegrationOutboxDeliveryStatus;
use App\Enums\IntegrationOutboxStatus;
use App\Models\IntegrationOutbox;
use App\Models\IntegrationOutboxDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class IntegrationLeaseReclaimer
{
    /**
     * @return array{outbox_reclaimed: int, deliveries_reclaimed: int}
     */
    public function reclaimExpired(?int $organizationId = null): array
    {
        $outboxLease = (int) config('integrations.outbox.lease_seconds', 120);
        $deliveryLease = (int) config('integrations.deliveries.lease_seconds', 120);

        return [
            'outbox_reclaimed' => $this->reclaimOutbox($outboxLease, $organizationId),
            'deliveries_reclaimed' => $this->reclaimDeliveries($deliveryLease, $organizationId),
        ];
    }

    private function reclaimOutbox(int $leaseSeconds, ?int $organizationId): int
    {
        return DB::transaction(function () use ($leaseSeconds, $organizationId): int {
            $cutoff = Carbon::now()->subSeconds($leaseSeconds);

            $query = IntegrationOutbox::query()
                ->where('status', IntegrationOutboxStatus::Processing->value)
                ->whereNotNull('locked_at')
                ->where('locked_at', '<=', $cutoff);

            if ($organizationId !== null) {
                $query->where('organization_id', $organizationId);
            }

            $count = 0;

            foreach ($query->lockForUpdate()->get() as $row) {
                /** @var IntegrationOutbox $row */
                $row->forceFill([
                    'status' => IntegrationOutboxStatus::Pending,
                    'locked_at' => null,
                    'locked_by_worker' => null,
                    'available_at' => now(),
                    'last_error_code' => 'lease_expired',
                    'last_error_message' => 'Outbox materialization lease expired; reclaimed for retry.',
                ])->save();
                $count++;
            }

            return $count;
        });
    }

    private function reclaimDeliveries(int $leaseSeconds, ?int $organizationId): int
    {
        return DB::transaction(function () use ($leaseSeconds, $organizationId): int {
            $cutoff = Carbon::now()->subSeconds($leaseSeconds);

            $query = IntegrationOutboxDelivery::query()
                ->where('status', IntegrationOutboxDeliveryStatus::Processing->value)
                ->whereNotNull('locked_at')
                ->where('locked_at', '<=', $cutoff);

            if ($organizationId !== null) {
                $query->where('organization_id', $organizationId);
            }

            $count = 0;

            foreach ($query->lockForUpdate()->get() as $row) {
                /** @var IntegrationOutboxDelivery $row */
                $priorAttempts = $row->attempt_count;
                $status = $priorAttempts > 0
                    ? IntegrationOutboxDeliveryStatus::Retrying
                    : IntegrationOutboxDeliveryStatus::Pending;

                $row->forceFill([
                    'status' => $status,
                    'locked_at' => null,
                    'locked_by_worker' => null,
                    'available_at' => now(),
                    'last_error_code' => 'lease_expired',
                    'last_error_message' => 'Delivery processing lease expired; reclaimed for retry.',
                ])->save();
                $count++;
            }

            return $count;
        });
    }
}
