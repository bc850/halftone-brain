<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationOutboxDeliveryStatus;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Requests\AbandonIntegrationOutboxDeliveryRequest;
use App\Http\Requests\ReplayIntegrationOutboxDeliveryRequest;
use App\Models\AuditEvent;
use App\Models\IntegrationOutbox;
use App\Models\IntegrationOutboxDelivery;
use App\Models\Organization;
use App\Models\Quote;
use App\Support\Integrations\Outbox\IntegrationDeliveryLifecycleService;
use App\Support\Integrations\Outbox\IntegrationOperationalProjection;
use App\Support\Integrations\Outbox\IntegrationOutboxHealthReporter;
use App\Support\Integrations\Outbox\IntegrationOutboxLabels;
use App\Support\Integrations\Outbox\StaleIntegrationDeliveryStateException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class IntegrationOutboxController extends Controller
{
    use RequiresTenantContext;

    private const DATE_RANGE_MAX_DAYS = 90;

    public function __construct(
        private IntegrationOutboxHealthReporter $health,
        private IntegrationOperationalProjection $projection,
        private IntegrationDeliveryLifecycleService $lifecycle,
    ) {}

    public function index(Request $request): Response
    {
        $tenant = $this->requireTenantContext();
        $this->authorize('viewAny', IntegrationOutboxDelivery::class);

        $filters = $this->filters($request);
        $canReplay = $tenant->canOrg('integrations.outbox.replay');
        $canAbandon = $tenant->canOrg('integrations.outbox.abandon');

        $query = IntegrationOutboxDelivery::query()
            ->with(['outbox', 'organization'])
            ->where('organization_id', $tenant->organizationId)
            ->where('parent_account_id', $tenant->parentAccountId)
            ->orderByDesc('id');

        $this->applyFilters($query, $filters, $tenant->organizationId);

        $paginator = $query->paginate(25)->withQueryString();

        $rows = $paginator->getCollection()->map(function (IntegrationOutboxDelivery $delivery) use ($canReplay, $canAbandon): array {
            $outbox = $delivery->outbox;
            $business = $outbox !== null
                ? $this->projection->resolveBusinessContext($outbox)
                : [
                    'quote_id' => null,
                    'quote_number' => null,
                    'quote_revision_id' => null,
                    'deal_id' => null,
                    'company_name' => null,
                ];
            $projected = $this->projection->projectDelivery($delivery, $canReplay, $canAbandon);

            return [
                'id' => $projected['id'],
                'outbox_id' => (int) $delivery->integration_outbox_id,
                'business_event' => $outbox !== null
                    ? IntegrationOutboxLabels::eventType($outbox->event_type)
                    : 'Unknown event',
                'event_type' => $outbox?->event_type,
                'quote_number' => $business['quote_number'],
                'company_name' => $business['company_name'],
                'consumer_label' => $projected['consumer_label'],
                'consumer_key' => $projected['consumer_key'],
                'status' => $projected['status'],
                'status_label' => $projected['status_label'],
                'attempt_count' => $projected['attempt_count'],
                'next_attempt_at' => in_array($projected['status'], ['pending', 'retrying'], true)
                    ? $projected['available_at']
                    : null,
                'last_activity_at' => $projected['updated_at'],
                'problem_summary' => $projected['error']['message'] ?? $projected['error']['code'],
                'organization' => $delivery->organization?->name,
                'lease_active' => $projected['lease_active'],
                'lease_expired' => $projected['lease_expired'],
                'can_replay' => $projected['can_replay'],
                'can_abandon' => $projected['can_abandon'],
            ];
        })->values();

        return Inertia::render('integrations/OutboxIndex', [
            'deliveries' => [
                'data' => $rows,
                'links' => $paginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'filters' => $filters,
            'health' => $this->healthCards($tenant->organizationId),
            'statusOptions' => $this->statusOptions(),
            'consumerOptions' => $this->consumerOptions($tenant->organizationId),
            'eventTypeOptions' => $this->eventTypeOptions(),
            'canReplay' => $canReplay,
            'canAbandon' => $canAbandon,
        ]);
    }

    public function health(?Organization $organization): RedirectResponse
    {
        $tenant = $this->requireTenantContext();
        $this->authorize('viewAny', IntegrationOutboxDelivery::class);

        return redirect()->route('org.integrations.outbox.index', $tenant->organization);
    }

    public function showEvent(?Organization $organization, IntegrationOutbox $outboxEvent): Response
    {
        $this->requireTenantContext();
        $this->authorize('viewAny', IntegrationOutboxDelivery::class);

        $tenant = TenantContext::get();
        $canReplay = $tenant->canOrg('integrations.outbox.replay');
        $canAbandon = $tenant->canOrg('integrations.outbox.abandon');

        $deliveries = IntegrationOutboxDelivery::query()
            ->where('integration_outbox_id', $outboxEvent->id)
            ->where('organization_id', $tenant->organizationId)
            ->orderBy('id')
            ->get()
            ->map(fn (IntegrationOutboxDelivery $delivery): array => $this->projection->projectDelivery(
                $delivery,
                $canReplay,
                $canAbandon,
            ))
            ->values();

        return Inertia::render('integrations/OutboxEventShow', [
            'event' => $this->projectOutboxEvent($outboxEvent),
            'payload_fields' => $this->projection->projectPayload($outboxEvent),
            'business' => $this->projection->resolveBusinessContext($outboxEvent),
            'deliveries' => $deliveries,
            'canReplay' => $canReplay,
            'canAbandon' => $canAbandon,
        ]);
    }

    public function showDelivery(?Organization $organization, IntegrationOutboxDelivery $outboxDelivery): Response
    {
        $this->requireTenantContext();
        $this->authorize('view', $outboxDelivery);

        $tenant = TenantContext::get();
        $canReplay = $tenant->canOrg('integrations.outbox.replay');
        $canAbandon = $tenant->canOrg('integrations.outbox.abandon');

        $outboxDelivery->loadMissing('outbox');
        $outbox = $outboxDelivery->outbox;

        $audits = AuditEvent::query()
            ->where('organization_id', $tenant->organizationId)
            ->where('subject_type', IntegrationOutboxDelivery::class)
            ->where('subject_id', $outboxDelivery->id)
            ->whereIn('action', [
                IntegrationDeliveryLifecycleService::AUDIT_REPLAYED,
                IntegrationDeliveryLifecycleService::AUDIT_ABANDONED,
                'integrations.outbox_delivery.configuration_released',
            ])
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'action', 'actor_user_id', 'before_json', 'after_json', 'correlation_id', 'created_at'])
            ->map(static fn (AuditEvent $event): array => [
                'id' => $event->id,
                'action' => $event->action,
                'actor_user_id' => $event->actor_user_id,
                'before' => $event->before_json,
                'after' => $event->after_json,
                'correlation_id' => $event->correlation_id,
                'created_at' => $event->created_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('integrations/OutboxDeliveryShow', [
            'delivery' => $this->projection->projectDelivery($outboxDelivery, $canReplay, $canAbandon),
            'event' => $outbox !== null ? $this->projectOutboxEvent($outbox) : null,
            'payload_fields' => $outbox !== null ? $this->projection->projectPayload($outbox) : [],
            'business' => $outbox !== null ? $this->projection->resolveBusinessContext($outbox) : null,
            'audits' => $audits,
            'canReplay' => $canReplay,
            'canAbandon' => $canAbandon,
        ]);
    }

    public function replay(
        ReplayIntegrationOutboxDeliveryRequest $request,
        ?Organization $organization,
        IntegrationOutboxDelivery $outboxDelivery,
    ): RedirectResponse {
        $this->requireTenantContext();

        try {
            $this->lifecycle->replay(
                delivery: $outboxDelivery,
                reason: $request->reason(),
                actor: $request->user(),
                resetAttempts: $request->resetAttempts(),
                expectedStatus: $request->expectedStatus(),
            );
        } catch (StaleIntegrationDeliveryStateException $exception) {
            throw new HttpException(409, $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        return back()->with('success', __('Delivery queued for another attempt.'));
    }

    public function abandon(
        AbandonIntegrationOutboxDeliveryRequest $request,
        ?Organization $organization,
        IntegrationOutboxDelivery $outboxDelivery,
    ): RedirectResponse {
        $this->requireTenantContext();

        try {
            $this->lifecycle->abandon(
                delivery: $outboxDelivery,
                reason: $request->reason(),
                actor: $request->user(),
                expectedStatus: $request->expectedStatus(),
            );
        } catch (StaleIntegrationDeliveryStateException $exception) {
            throw new HttpException(409, $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        return back()->with('success', __('Delivery abandoned.'));
    }

    /**
     * @return array{
     *     status: string|null,
     *     consumer: string|null,
     *     event_type: string|null,
     *     correlation_id: string|null,
     *     quote_number: string|null,
     *     delivery_id: int|null,
     *     outbox_id: int|null,
     *     date_from: string|null,
     *     date_to: string|null,
     *     include_completed: bool
     * }
     */
    private function filters(Request $request): array
    {
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        if (is_string($dateFrom) && is_string($dateTo) && $dateFrom !== '' && $dateTo !== '') {
            $from = strtotime($dateFrom);
            $to = strtotime($dateTo);

            if ($from !== false && $to !== false && ($to - $from) > self::DATE_RANGE_MAX_DAYS * 86400) {
                $dateFrom = date('Y-m-d', $to - self::DATE_RANGE_MAX_DAYS * 86400);
            }
        }

        return [
            'status' => $this->nullableString($request->query('status')),
            'consumer' => $this->nullableString($request->query('consumer')),
            'event_type' => $this->nullableString($request->query('event_type')),
            'correlation_id' => $this->nullableString($request->query('correlation_id')),
            'quote_number' => $this->nullableString($request->query('quote_number')),
            'delivery_id' => is_numeric($request->query('delivery_id')) ? (int) $request->query('delivery_id') : null,
            'outbox_id' => is_numeric($request->query('outbox_id')) ? (int) $request->query('outbox_id') : null,
            'date_from' => is_string($dateFrom) && $dateFrom !== '' ? $dateFrom : null,
            'date_to' => is_string($dateTo) && $dateTo !== '' ? $dateTo : null,
            'include_completed' => $request->boolean('include_completed'),
        ];
    }

    /**
     * @param  Builder<IntegrationOutboxDelivery>  $query
     * @param  array{
     *     status: string|null,
     *     consumer: string|null,
     *     event_type: string|null,
     *     correlation_id: string|null,
     *     quote_number: string|null,
     *     delivery_id: int|null,
     *     outbox_id: int|null,
     *     date_from: string|null,
     *     date_to: string|null,
     *     include_completed: bool
     * }  $filters
     */
    private function applyFilters($query, array $filters, int $organizationId): void
    {
        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        } elseif (! $filters['include_completed']) {
            $query->whereNotIn('status', [
                IntegrationOutboxDeliveryStatus::Succeeded->value,
                IntegrationOutboxDeliveryStatus::Abandoned->value,
            ]);
        }

        if ($filters['consumer'] !== null) {
            $query->where('consumer_key', $filters['consumer']);
        }

        if ($filters['correlation_id'] !== null) {
            $query->where('correlation_id', $filters['correlation_id']);
        }

        if ($filters['delivery_id'] !== null) {
            $query->whereKey($filters['delivery_id']);
        }

        if ($filters['outbox_id'] !== null) {
            $query->where('integration_outbox_id', $filters['outbox_id']);
        }

        if ($filters['date_from'] !== null) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if ($filters['date_to'] !== null) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if ($filters['event_type'] !== null) {
            $query->whereHas('outbox', static function ($outbox) use ($filters): void {
                $outbox->where('event_type', $filters['event_type']);
            });
        }

        if ($filters['quote_number'] !== null) {
            $quoteIds = Quote::query()
                ->where('organization_id', $organizationId)
                ->where('quote_number', $filters['quote_number'])
                ->pluck('id');

            if ($quoteIds->isEmpty()) {
                $query->whereRaw('0 = 1');
            } else {
                $query->whereHas('outbox', static function ($outbox) use ($quoteIds): void {
                    $outbox->where(function ($inner) use ($quoteIds): void {
                        foreach ($quoteIds as $quoteId) {
                            $inner->orWhere('payload_json->quote_id', $quoteId);
                        }
                    });
                });
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function healthCards(int $organizationId): array
    {
        $report = $this->health->report($organizationId);
        $byStatus = $report['deliveries_by_status'];

        return [
            'waiting' => (int) ($byStatus['pending'] ?? 0),
            'processing' => (int) ($byStatus['processing'] ?? 0),
            'retrying' => (int) ($byStatus['retrying'] ?? 0),
            'blocked_configuration' => (int) ($byStatus['blocked_configuration'] ?? 0),
            'failed' => (int) ($byStatus['failed'] ?? 0),
            'dead' => (int) ($byStatus['dead'] ?? 0),
            'abandoned' => (int) ($byStatus['abandoned'] ?? 0),
            'succeeded' => (int) ($byStatus['succeeded'] ?? 0),
            'oldest_waiting_age_seconds' => $report['oldest_eligible_pending_age_seconds'],
            'oldest_blocked_age_seconds' => $report['oldest_blocked_configuration_age_seconds'],
            'last_successful_delivery_at' => $report['last_successful_delivery_at'],
            'active_lease_count' => $report['currently_leased_count'],
            'expired_lease_count' => $report['expired_lease_count'],
            'by_consumer' => $report['deliveries_by_consumer'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectOutboxEvent(IntegrationOutbox $outbox): array
    {
        return [
            'id' => (int) $outbox->id,
            'event_type' => $outbox->event_type,
            'event_label' => IntegrationOutboxLabels::eventType($outbox->event_type),
            'schema_version' => (int) $outbox->schema_version,
            'aggregate_type' => $outbox->aggregate_type,
            'aggregate_id' => (int) $outbox->aggregate_id,
            'status' => $outbox->status->value,
            'status_label' => IntegrationOutboxLabels::outboxStatus($outbox->status->value),
            'correlation_id' => $outbox->correlation_id,
            'available_at' => $outbox->available_at->toIso8601String(),
            'locked_at' => $outbox->locked_at?->toIso8601String(),
            'dispatched_at' => $outbox->dispatched_at?->toIso8601String(),
            'created_at' => $outbox->created_at?->toIso8601String(),
            'updated_at' => $outbox->updated_at?->toIso8601String(),
            'error' => $this->projection->projectError($outbox->last_error_code, $outbox->last_error_message),
            'organization_id' => $outbox->organization_id,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        $options = [];

        foreach (IntegrationOutboxLabels::deliveryStatuses() as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function eventTypeOptions(): array
    {
        $options = [];

        foreach (IntegrationOutboxLabels::eventTypes() as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function consumerOptions(int $organizationId): array
    {
        $keys = IntegrationOutboxDelivery::query()
            ->where('organization_id', $organizationId)
            ->distinct()
            ->orderBy('consumer_key')
            ->pluck('consumer_key');

        $options = [];

        foreach (IntegrationOutboxLabels::consumers() as $value => $label) {
            $options[$value] = ['value' => $value, 'label' => $label];
        }

        foreach ($keys as $key) {
            $options[(string) $key] = [
                'value' => (string) $key,
                'label' => IntegrationOutboxLabels::consumer((string) $key),
            ];
        }

        return array_values($options);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
