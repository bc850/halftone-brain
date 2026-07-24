<?php

namespace App\Http\Controllers;

use App\Enums\SalesTaxStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Controllers\Concerns\ScopesQueriesToTenant;
use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\OrganizationCompany;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    use RequiresTenantContext;
    use ScopesQueriesToTenant;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Company::class);

        /** @var User $user */
        $user = $request->user();

        $companiesQuery = Company::query()->with('owner:id,name')->withCount(['contacts', 'deals']);

        if (TenantContext::has()) {
            $companiesQuery = $this->scopeCompaniesForRequest($companiesQuery);
        } else {
            $companiesQuery->visibleTo($user);
        }

        $companies = $companiesQuery
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
        $tenant = $this->requireTenantContext();

        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();
        $data['owner_id'] = $user->isAdmin() && isset($data['owner_id'])
            ? $data['owner_id']
            : $user->id;
        $data['parent_account_id'] = $tenant->parentAccountId;

        $company = Company::query()->create($data);

        OrganizationCompany::query()->create([
            'organization_id' => $tenant->organizationId,
            'company_id' => $company->id,
            'parent_account_id' => $tenant->parentAccountId,
            'lifecycle_status' => 'prospect',
            'relationship_status' => 'new',
            'tax_posture' => 'unknown',
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company created.')]);

        return redirect()->to(TenantRoute::to('companies.show', $company));
    }

    public function show(Request $request, ?Organization $organization, Company $company): Response
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

    public function edit(Request $request, ?Organization $organization, Company $company): Response
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

    public function update(UpdateCompanyRequest $request, ?Organization $organization, Company $company): RedirectResponse
    {
        $this->requireTenantContext();

        $company->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company updated.')]);

        return redirect()->to(TenantRoute::to('companies.show', $company));
    }

    public function destroy(?Organization $organization, Company $company): RedirectResponse
    {
        $this->requireTenantContext();
        $this->authorize('delete', $company);

        $company->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company deleted.')]);

        return redirect()->to(TenantRoute::to('companies.index'));
    }
}
