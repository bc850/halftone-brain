<?php

namespace App\Http\Controllers;

use App\Enums\QuoteApprovalRequestStatus;
use App\Http\Controllers\Concerns\HandlesQuoteDrafts;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Requests\ApproveQuoteApprovalRequest;
use App\Http\Requests\RejectQuoteApprovalRequest;
use App\Http\Resources\ApprovalRequestResource;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\QuoteApprovalRequest;
use App\Support\Quotes\Approval\QuoteApprovalEvaluator;
use App\Support\Quotes\Approval\QuoteApprovalWorkflowService;
use App\Support\Tenancy\TenantRoute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The approver's inbox.
 *
 * Requests are listed with the reasons captured when they were raised, so an
 * approver reads what the salesperson was told rather than a re-derived answer.
 * Deciding is delegated to the workflow service, which owns the locking.
 */
class QuoteApprovalQueueController extends Controller
{
    use HandlesQuoteDrafts;
    use RequiresTenantContext;

    public function __construct(private QuoteApprovalWorkflowService $workflow) {}

    public function index(Request $request): Response
    {
        $tenant = $this->requireTenantContext();
        $this->authorize('approveAny', Quote::class);

        $filters = $this->filters($request);

        return Inertia::render('quotes/ApprovalQueue', [
            'requests' => ApprovalRequestResource::collection(
                $this->queue($tenant->organizationId, $filters, $request),
            ),
            'filters' => $filters,
            'statuses' => array_map(
                static fn (QuoteApprovalRequestStatus $status): array => [
                    'value' => $status->value,
                    'label' => ucfirst($status->value),
                ],
                QuoteApprovalRequestStatus::cases(),
            ),
            'reasonOptions' => $this->reasonOptions(),
            'salespeople' => $this->salespeople($tenant->organizationId),
        ]);
    }

    public function approve(
        ApproveQuoteApprovalRequest $request,
        ?Organization $organization,
        QuoteApprovalRequest $approvalRequest,
    ): RedirectResponse {
        $this->requireTenantContext();

        $this->runDraftMutation(function () use ($request, $approvalRequest): null {
            $this->workflow->approve(
                request: $approvalRequest,
                expectedQuoteLockVersion: $request->expectedQuoteLockVersion(),
                expectedRevisionLockVersion: $request->expectedLockVersion(),
                actor: $request->user(),
                actorMembership: $this->actingMembership(),
            );

            return null;
        });

        return $this->done(__('Quote approved.'));
    }

    public function reject(
        RejectQuoteApprovalRequest $request,
        ?Organization $organization,
        QuoteApprovalRequest $approvalRequest,
    ): RedirectResponse {
        $this->requireTenantContext();

        $this->runDraftMutation(function () use ($request, $approvalRequest): null {
            $this->workflow->reject(
                request: $approvalRequest,
                expectedQuoteLockVersion: $request->expectedQuoteLockVersion(),
                expectedRevisionLockVersion: $request->expectedLockVersion(),
                reason: $request->reason(),
                actor: $request->user(),
                actorMembership: $this->actingMembership(),
            );

            return null;
        });

        return $this->done(__('Quote rejected.'));
    }

    /**
     * @return array{status: string, salesperson: int|null, min_amount: string|null, reason: string|null, min_age_days: int|null}
     */
    private function filters(Request $request): array
    {
        $status = (string) $request->query('status', QuoteApprovalRequestStatus::Pending->value);
        $reason = $this->trimmedQuery($request, 'reason');
        $minAmount = $this->trimmedQuery($request, 'min_amount');

        return [
            'status' => QuoteApprovalRequestStatus::tryFrom($status) !== null || $status === 'all'
                ? $status
                : QuoteApprovalRequestStatus::Pending->value,
            'salesperson' => $request->integer('salesperson') ?: null,
            'min_amount' => $minAmount !== null && preg_match('/^\d+(\.\d{1,2})?$/', $minAmount) === 1
                ? $minAmount
                : null,
            'reason' => $reason !== null && array_key_exists($reason, QuoteApprovalEvaluator::reasonCatalog())
                ? $reason
                : null,
            'min_age_days' => $request->integer('min_age_days') ?: null,
        ];
    }

    /**
     * Age and amount filter in SQL where they can; the reason filter reads the
     * snapshot JSON, which is portable across drivers only in PHP.
     *
     * @param  array{status: string, salesperson: int|null, min_amount: string|null, reason: string|null, min_age_days: int|null}  $filters
     * @return Collection<int, QuoteApprovalRequest>
     */
    private function queue(int $organizationId, array $filters, Request $httpRequest): Collection
    {
        $requests = QuoteApprovalRequest::query()
            ->where('organization_id', $organizationId)
            ->when(
                $filters['status'] !== 'all',
                fn (Builder $query): Builder => $query->where('status', $filters['status']),
            )
            ->when(
                $filters['salesperson'] !== null,
                fn (Builder $query): Builder => $query->where(
                    'requested_by_membership_id',
                    $filters['salesperson'],
                ),
            )
            ->when(
                $filters['min_age_days'] !== null,
                fn (Builder $query): Builder => $query->where(
                    'requested_at',
                    '<=',
                    now()->subDays((int) $filters['min_age_days']),
                ),
            )
            ->with(['quote', 'quoteRevision', 'requestedByMembership.user'])
            ->orderBy('status')
            ->orderBy('requested_at')
            ->limit(200)
            ->get();

        return $requests
            ->filter(fn (QuoteApprovalRequest $request): bool => $this->matchesReason($request, $filters['reason']))
            ->filter(fn (QuoteApprovalRequest $request): bool => $this->matchesAmount($request, $filters['min_amount']))
            ->filter(fn (QuoteApprovalRequest $request): bool => $request->quote !== null
                && $httpRequest->user()?->can('approve', $request->quote) === true)
            ->values();
    }

    private function matchesReason(QuoteApprovalRequest $request, ?string $reason): bool
    {
        if ($reason === null) {
            return true;
        }

        $reasons = $request->rule_snapshot_json['reasons'] ?? [];

        return is_array($reasons) && in_array($reason, $reasons, true);
    }

    private function matchesAmount(QuoteApprovalRequest $request, ?string $minAmount): bool
    {
        if ($minAmount === null) {
            return true;
        }

        $basis = $request->rule_snapshot_json['threshold_basis_cents'] ?? 0;

        return (is_int($basis) ? $basis : 0) >= (int) round(((float) $minAmount) * 100);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function reasonOptions(): array
    {
        $options = [];

        foreach (QuoteApprovalEvaluator::reasonCatalog() as $reason => $explanation) {
            $options[] = ['value' => $reason, 'label' => $explanation];
        }

        return $options;
    }

    /**
     * Everyone who has actually raised a request, so the filter never offers an
     * option that returns nothing.
     *
     * @return list<array{value: int, label: string}>
     */
    private function salespeople(int $organizationId): array
    {
        return array_values(Membership::query()
            ->whereIn(
                'id',
                QuoteApprovalRequest::query()
                    ->where('organization_id', $organizationId)
                    ->select('requested_by_membership_id'),
            )
            ->with('user')
            ->get()
            ->map(static fn (Membership $membership): array => [
                'value' => $membership->id,
                'label' => $membership->user->name ?? 'Unknown',
            ])
            ->sortBy('label')
            ->values()
            ->all());
    }

    private function trimmedQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function actingMembership(): Membership
    {
        return Membership::query()->findOrFail($this->requireTenantContext()->organizationMembershipId);
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect()->to(TenantRoute::to('quote-approvals.index'));
    }
}
