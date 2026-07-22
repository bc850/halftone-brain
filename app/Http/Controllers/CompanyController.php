<?php

namespace App\Http\Controllers;

use App\Enums\SalesTaxStatus;
use App\Enums\UserRole;
use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Company::class);

        /** @var User $user */
        $user = $request->user();

        $companies = Company::query()
            ->visibleTo($user)
            ->with('owner:id,name')
            ->withCount(['contacts', 'deals'])
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('companies/Index', [
            'companies' => $companies,
            'filters' => [
                'search' => $request->string('search')->toString(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Company::class);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('companies/Create', [
            'salesTaxStatuses' => collect(SalesTaxStatus::cases())->map(fn (SalesTaxStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ]),
            'salespeople' => $user->isAdmin()
                ? User::query()
                    ->whereIn('role', [UserRole::Salesman, UserRole::Admin])
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : [],
        ]);
    }

    public function store(StoreCompanyRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();
        $data['owner_id'] = $user->isAdmin() && isset($data['owner_id'])
            ? $data['owner_id']
            : $user->id;

        $company = Company::query()->create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company created.')]);

        return to_route('companies.show', $company);
    }

    public function show(Request $request, Company $company): Response
    {
        $this->authorize('view', $company);

        $company->load([
            'owner:id,name',
            'contacts' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('last_name'),
            'deals' => fn ($query) => $query->with('owner:id,name')->latest(),
        ]);

        return Inertia::render('companies/Show', [
            'company' => [
                ...$company->toArray(),
                'deals' => $company->deals
                    ->map(fn (Deal $deal): array => DealController::summaryForCompany($deal))
                    ->values(),
            ],
            'canCreateDeal' => $request->user()->can('create', Deal::class),
        ]);
    }

    public function edit(Request $request, Company $company): Response
    {
        $this->authorize('update', $company);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('companies/Edit', [
            'company' => $company,
            'salesTaxStatuses' => collect(SalesTaxStatus::cases())->map(fn (SalesTaxStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ]),
            'salespeople' => $user->isAdmin()
                ? User::query()
                    ->whereIn('role', [UserRole::Salesman, UserRole::Admin])
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : [],
        ]);
    }

    public function update(UpdateCompanyRequest $request, Company $company): RedirectResponse
    {
        $company->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company updated.')]);

        return to_route('companies.show', $company);
    }

    public function destroy(Company $company): RedirectResponse
    {
        $this->authorize('delete', $company);

        $company->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company deleted.')]);

        return to_route('companies.index');
    }
}
