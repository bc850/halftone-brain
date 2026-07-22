<?php

namespace App\Http\Controllers;

use App\Enums\DealStage;
use App\Enums\UserRole;
use App\Http\Requests\StoreDealRequest;
use App\Http\Requests\UpdateDealRequest;
use App\Http\Resources\DealResource;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DealController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Deal::class);

        /** @var User $user */
        $user = $request->user();

        $deals = Deal::query()
            ->visibleTo($user)
            ->with([
                'company:id,name',
                'owner:id,name',
                'primaryContact:id,first_name,last_name',
            ])
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhereHas('company', fn ($companyQuery) => $companyQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->latest()
            ->get()
            ->groupBy(fn (Deal $deal): string => $deal->stage->value);

        $columns = collect(DealStage::pipelineOrder())->map(function (DealStage $stage) use ($deals, $user): array {
            return [
                'stage' => $stage->value,
                'label' => $stage->label(),
                'deals' => $deals->get($stage->value, collect())
                    ->map(fn (Deal $deal): array => DealResource::make($deal, $user))
                    ->values(),
            ];
        });

        return Inertia::render('deals/Index', [
            'columns' => $columns,
            'filters' => [
                'search' => $request->string('search')->toString(),
            ],
            'stages' => collect(DealStage::pipelineOrder())->map(fn (DealStage $stage): array => [
                'value' => $stage->value,
                'label' => $stage->label(),
            ]),
            'canCreate' => $user->can('create', Deal::class),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Deal::class);

        /** @var User $user */
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: null;
        $contactId = $request->integer('contact_id') ?: null;

        if ($contactId) {
            $contact = Contact::query()
                ->visibleTo($user)
                ->find($contactId);

            if ($contact) {
                $companyId = $contact->company_id;
            } else {
                $contactId = null;
            }
        }

        $contacts = $companyId
            ? Contact::query()
                ->where('company_id', $companyId)
                ->orderByDesc('is_primary')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name', 'company_id', 'is_primary'])
            : collect();

        if (! $contactId && $companyId) {
            $contactId = $contacts->firstWhere('is_primary', true)?->id;
        }

        return Inertia::render('deals/Create', [
            'companies' => Company::query()
                ->visibleTo($user)
                ->orderBy('name')
                ->get(['id', 'name']),
            'contacts' => $contacts->map(fn (Contact $contact): array => [
                'id' => $contact->id,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'company_id' => $contact->company_id,
            ])->values(),
            'selectedCompanyId' => $companyId,
            'selectedPrimaryContactId' => $contactId,
            'stages' => collect(DealStage::pipelineOrder())->map(fn (DealStage $stage): array => [
                'value' => $stage->value,
                'label' => $stage->label(),
            ]),
            'salespeople' => $user->isAdmin()
                ? User::query()
                    ->whereIn('role', [UserRole::Salesman, UserRole::Admin])
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : [],
        ]);
    }

    public function store(StoreDealRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();
        $contactIds = $data['contact_ids'] ?? [];
        unset($data['contact_ids']);

        $data['owner_id'] = $user->isAdmin() && ! empty($data['owner_id'])
            ? $data['owner_id']
            : $user->id;

        $deal = DB::transaction(function () use ($data, $contactIds): Deal {
            $deal = Deal::query()->create($data);

            if ($deal->primary_contact_id) {
                $contactIds[] = $deal->primary_contact_id;
            }

            $deal->contacts()->sync(array_values(array_unique($contactIds)));

            return $deal;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deal created.')]);

        return to_route('deals.show', $deal);
    }

    public function show(Deal $deal): Response
    {
        $this->authorize('view', $deal);

        /** @var User $user */
        $user = request()->user();

        $deal->load([
            'company:id,name',
            'owner:id,name',
            'primaryContact:id,first_name,last_name,email,phone',
            'contacts:id,first_name,last_name,email,phone',
        ]);

        return Inertia::render('deals/Show', [
            'deal' => DealResource::make($deal, $user),
            'stages' => collect(DealStage::pipelineOrder())->map(fn (DealStage $stage): array => [
                'value' => $stage->value,
                'label' => $stage->label(),
            ]),
        ]);
    }

    public function edit(Request $request, Deal $deal): Response
    {
        $this->authorize('update', $deal);

        /** @var User $user */
        $user = $request->user();
        $deal->load('contacts:id');
        $companyId = $request->integer('company_id') ?: $deal->company_id;

        $dealPayload = DealResource::make($deal, $user);
        $dealPayload['company_id'] = $companyId;
        $dealPayload['contact_ids'] = $deal->contacts->pluck('id');

        return Inertia::render('deals/Edit', [
            'deal' => $dealPayload,
            'companies' => Company::query()
                ->visibleTo($user)
                ->orderBy('name')
                ->get(['id', 'name']),
            'contacts' => Contact::query()
                ->where('company_id', $companyId)
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name', 'company_id']),
            'stages' => collect(DealStage::pipelineOrder())->map(fn (DealStage $stage): array => [
                'value' => $stage->value,
                'label' => $stage->label(),
            ]),
            'salespeople' => $user->isAdmin()
                ? User::query()
                    ->whereIn('role', [UserRole::Salesman, UserRole::Admin])
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : [],
        ]);
    }

    public function update(UpdateDealRequest $request, Deal $deal): RedirectResponse
    {
        $data = $request->validated();
        $contactIds = $data['contact_ids'] ?? [];
        unset($data['contact_ids']);

        DB::transaction(function () use ($deal, $data, $contactIds): void {
            $deal->update($data);

            if ($deal->primary_contact_id) {
                $contactIds[] = $deal->primary_contact_id;
            }

            $deal->contacts()->sync(array_values(array_unique($contactIds)));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deal updated.')]);

        return to_route('deals.show', $deal);
    }

    public function destroy(Deal $deal): RedirectResponse
    {
        $this->authorize('delete', $deal);

        $deal->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deal deleted.')]);

        return to_route('deals.index');
    }

    public function updateStage(Request $request, Deal $deal): RedirectResponse
    {
        $this->authorize('update', $deal);

        $validated = $request->validate([
            'stage' => ['required', Rule::enum(DealStage::class)],
        ]);

        $deal->update(['stage' => $validated['stage']]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deal stage updated.')]);

        return back();
    }

    /**
     * @return array{id: int, name: string, stage: string, amount: string|null}
     */
    public static function summaryForCompany(Deal $deal): array
    {
        return [
            'id' => $deal->id,
            'name' => $deal->name,
            'stage' => $deal->stage->value,
            'amount' => $deal->amount_cents !== null ? Money::centsToDollars($deal->amount_cents) : null,
        ];
    }
}
