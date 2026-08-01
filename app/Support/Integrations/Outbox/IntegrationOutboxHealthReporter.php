<?php

namespace App\Support\Integrations\Outbox;

use App\Enums\IntegrationOutboxDeliveryStatus;
use App\Enums\IntegrationOutboxStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class IntegrationOutboxHealthReporter
{
    /**
     * @return array{
     *     database: string,
     *     outbox_by_status: array<string, int>,
     *     deliveries_by_status: array<string, int>,
     *     deliveries_by_consumer: array<string, array<string, int>>,
     *     oldest_eligible_pending_age_seconds: int|null,
     *     oldest_blocked_configuration_age_seconds: int|null,
     *     last_successful_delivery_at: string|null,
     *     dead_delivery_count: int,
     *     currently_leased_count: int,
     *     expired_lease_count: int
     * }
     */
    public function report(?int $organizationId = null): array
    {
        $outboxLease = (int) config('integrations.outbox.lease_seconds', 120);
        $deliveryLease = (int) config('integrations.deliveries.lease_seconds', 120);
        $now = Carbon::now();

        return [
            'database' => (string) config('database.connections.'.config('database.default').'.database'),
            'outbox_by_status' => $this->countsFromTable('integration_outbox', $organizationId, IntegrationOutboxStatus::cases()),
            'deliveries_by_status' => $this->countsFromTable('integration_outbox_deliveries', $organizationId, IntegrationOutboxDeliveryStatus::cases()),
            'deliveries_by_consumer' => $this->deliveriesByConsumer($organizationId),
            'oldest_eligible_pending_age_seconds' => $this->oldestAgeFromTable(
                'integration_outbox_deliveries',
                $organizationId,
                'available_at',
                [
                    IntegrationOutboxDeliveryStatus::Pending->value,
                    IntegrationOutboxDeliveryStatus::Retrying->value,
                ],
                requireAvailableNow: true,
            ),
            'oldest_blocked_configuration_age_seconds' => $this->oldestAgeFromTable(
                'integration_outbox_deliveries',
                $organizationId,
                'blocked_at',
                [IntegrationOutboxDeliveryStatus::BlockedConfiguration->value],
                requireAvailableNow: false,
            ),
            'last_successful_delivery_at' => $this->lastSuccessAt($organizationId),
            'dead_delivery_count' => $this->tableQuery('integration_outbox_deliveries', $organizationId)
                ->where('status', IntegrationOutboxDeliveryStatus::Dead->value)
                ->count(),
            'currently_leased_count' => $this->tableQuery('integration_outbox', $organizationId)
                ->where('status', IntegrationOutboxStatus::Processing->value)
                ->count()
                + $this->tableQuery('integration_outbox_deliveries', $organizationId)
                    ->where('status', IntegrationOutboxDeliveryStatus::Processing->value)
                    ->count(),
            'expired_lease_count' => $this->tableQuery('integration_outbox', $organizationId)
                ->where('status', IntegrationOutboxStatus::Processing->value)
                ->whereNotNull('locked_at')
                ->where('locked_at', '<=', $now->copy()->subSeconds($outboxLease))
                ->count()
                + $this->tableQuery('integration_outbox_deliveries', $organizationId)
                    ->where('status', IntegrationOutboxDeliveryStatus::Processing->value)
                    ->whereNotNull('locked_at')
                    ->where('locked_at', '<=', $now->copy()->subSeconds($deliveryLease))
                    ->count(),
        ];
    }

    /**
     * @param  list<\BackedEnum>  $cases
     * @return array<string, int>
     */
    private function countsFromTable(string $table, ?int $organizationId, array $cases): array
    {
        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($cases as $case) {
            $counts[(string) $case->value] = 0;
        }

        $rows = $this->tableQuery($table, $organizationId)
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $key = (string) $row->status;
            $counts[$key] = (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function deliveriesByConsumer(?int $organizationId): array
    {
        $rows = $this->tableQuery('integration_outbox_deliveries', $organizationId)
            ->select('consumer_key', 'status', DB::raw('count(*) as aggregate'))
            ->groupBy('consumer_key', 'status')
            ->get();

        /** @var array<string, array<string, int>> $result */
        $result = [];

        foreach ($rows as $row) {
            $consumer = (string) $row->consumer_key;
            $status = (string) $row->status;
            $result[$consumer][$status] = (int) $row->aggregate;
        }

        return $result;
    }

    /**
     * @param  list<string>  $statuses
     */
    private function oldestAgeFromTable(
        string $table,
        ?int $organizationId,
        string $column,
        array $statuses,
        bool $requireAvailableNow,
    ): ?int {
        $query = $this->tableQuery($table, $organizationId)->whereIn('status', $statuses);

        if ($requireAvailableNow) {
            $query->where('available_at', '<=', Carbon::now());
        }

        $oldest = $query->orderBy($column)->value($column);

        if ($oldest === null) {
            return null;
        }

        return (int) max(0, Carbon::parse((string) $oldest)->diffInSeconds(Carbon::now()));
    }

    private function lastSuccessAt(?int $organizationId): ?string
    {
        $value = $this->tableQuery('integration_outbox_deliveries', $organizationId)
            ->where('status', IntegrationOutboxDeliveryStatus::Succeeded->value)
            ->max('succeeded_at');

        return $value === null ? null : Carbon::parse((string) $value)->toIso8601String();
    }

    private function tableQuery(string $table, ?int $organizationId): Builder
    {
        $query = DB::table($table);

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        return $query;
    }
}
