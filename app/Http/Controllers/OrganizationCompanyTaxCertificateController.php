<?php

namespace App\Http\Controllers;

use App\Enums\TaxExemptionCategory;
use App\Http\Controllers\Concerns\HandlesTaxConfiguration;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Requests\DecideTaxCertificateRequest;
use App\Http\Requests\StoreTaxCertificateRequest;
use App\Http\Requests\UpdateTaxCertificateRequest;
use App\Http\Resources\TaxCertificateResource;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationCompany;
use App\Models\OrganizationCompanyTaxCertificate;
use App\Models\User;
use App\Support\Tax\OrganizationCompanyTaxCertificateService;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRoute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Exemption certificates held for one customer of this organization.
 *
 * Certificates hang off the organization-company relationship, not the shared
 * company identity: two organizations may hold different evidence for the same
 * customer, and neither may read the other's.
 *
 * Nothing here deletes. A certificate that turns out to be wrong is rejected,
 * revoked, or expired, so what was relied on and when survives.
 */
class OrganizationCompanyTaxCertificateController extends Controller
{
    use HandlesTaxConfiguration;
    use RequiresTenantContext;

    public function __construct(private OrganizationCompanyTaxCertificateService $certificates) {}

    public function index(Request $request, ?Organization $organization, Company $company): Response
    {
        $this->requireTenantContext();
        $this->authorize('view', $company);
        $this->authorize('viewAny', OrganizationCompanyTaxCertificate::class);

        $organizationCompany = $this->requireOrganizationCompany($company);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('companies/TaxCertificates', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'organization_company_id' => $organizationCompany->id,
            ],
            'certificates' => TaxCertificateResource::collection(
                $this->certificatesFor($organizationCompany),
                self::mayViewEvidence(),
            ),
            'exemptionCategories' => array_map(
                fn (TaxExemptionCategory $category): array => [
                    'value' => $category->value,
                    'label' => $category->label(),
                ],
                TaxExemptionCategory::cases(),
            ),
            'canManage' => $user->can('create', OrganizationCompanyTaxCertificate::class),
            'canViewEvidence' => self::mayViewEvidence(),
            'companyUrl' => TenantRoute::to('companies.show', $company),
        ]);
    }

    public function store(
        StoreTaxCertificateRequest $request,
        ?Organization $organization,
        Company $company,
    ): RedirectResponse {
        $this->requireTenantContext();
        $organizationCompany = $this->requireOrganizationCompany($company);
        $this->authorize('createFor', [OrganizationCompanyTaxCertificate::class, $organizationCompany]);

        $data = $request->validated();

        $this->runTaxConfigurationMutation(fn (): OrganizationCompanyTaxCertificate => $this->certificates->create(
            organizationCompany: $organizationCompany,
            exemptionCategory: $request->exemptionCategory(),
            jurisdictionState: (string) $data['jurisdiction_state'],
            certificateFormType: (string) $data['certificate_form_type'],
            effectiveDate: (string) $data['effective_date'],
            certificateNumber: $data['certificate_number'] ?? null,
            evidenceReference: $data['evidence_reference'] ?? null,
            expirationDate: $data['expiration_date'] ?? null,
            internalNotes: $data['internal_notes'] ?? null,
            actor: $request->user(),
        ), 'certificate');

        return $this->done($company, __('Certificate recorded.'));
    }

    public function update(
        UpdateTaxCertificateRequest $request,
        ?Organization $organization,
        Company $company,
        OrganizationCompanyTaxCertificate $taxCertificate,
    ): RedirectResponse {
        $this->prepare($company, $taxCertificate, 'update');

        $this->runTaxConfigurationMutation(fn (): OrganizationCompanyTaxCertificate => $this->certificates->update(
            certificate: $taxCertificate,
            data: $request->certificateChanges(),
            actor: $request->user(),
        ), 'certificate');

        return $this->done($company, __('Certificate updated.'));
    }

    public function verify(
        Request $request,
        ?Organization $organization,
        Company $company,
        OrganizationCompanyTaxCertificate $taxCertificate,
    ): RedirectResponse {
        $this->prepare($company, $taxCertificate, 'decide');

        $this->runTaxConfigurationMutation(fn (): OrganizationCompanyTaxCertificate => $this->certificates->verify(
            certificate: $taxCertificate,
            verifiedBy: $this->actingMembership(),
            actor: $request->user(),
        ), 'certificate');

        return $this->done($company, __('Certificate verified.'));
    }

    public function reject(
        DecideTaxCertificateRequest $request,
        ?Organization $organization,
        Company $company,
        OrganizationCompanyTaxCertificate $taxCertificate,
    ): RedirectResponse {
        $this->prepare($company, $taxCertificate, 'decide');

        $this->runTaxConfigurationMutation(fn (): OrganizationCompanyTaxCertificate => $this->certificates->reject(
            certificate: $taxCertificate,
            rejectedBy: $this->actingMembership(),
            reason: $request->reason(),
            actor: $request->user(),
        ), 'certificate');

        return $this->done($company, __('Certificate rejected.'));
    }

    public function revoke(
        DecideTaxCertificateRequest $request,
        ?Organization $organization,
        Company $company,
        OrganizationCompanyTaxCertificate $taxCertificate,
    ): RedirectResponse {
        $this->prepare($company, $taxCertificate, 'decide');

        $this->runTaxConfigurationMutation(fn (): OrganizationCompanyTaxCertificate => $this->certificates->revoke(
            certificate: $taxCertificate,
            revokedBy: $this->actingMembership(),
            reason: $request->reason(),
            actor: $request->user(),
        ), 'certificate');

        return $this->done($company, __('Certificate revoked.'));
    }

    public function markExpired(
        Request $request,
        ?Organization $organization,
        Company $company,
        OrganizationCompanyTaxCertificate $taxCertificate,
    ): RedirectResponse {
        $this->prepare($company, $taxCertificate, 'decide');

        $this->runTaxConfigurationMutation(fn (): OrganizationCompanyTaxCertificate => $this->certificates->markExpired(
            certificate: $taxCertificate,
            actor: $request->user(),
        ), 'certificate');

        return $this->done($company, __('Certificate marked expired.'));
    }

    /**
     * Certificate numbers and internal notes are customer tax documents, so reading
     * them takes certificate authority even for someone who may see the list.
     */
    public static function mayViewEvidence(): bool
    {
        if (! TenantContext::has()) {
            return false;
        }

        $tenant = TenantContext::get();

        return $tenant->canOrg('crm.tax_certificate.view')
            || $tenant->canOrg('crm.tax_certificate.manage');
    }

    private function prepare(
        Company $company,
        OrganizationCompanyTaxCertificate $certificate,
        string $ability,
    ): void {
        $this->requireTenantContext();

        $organizationCompany = $this->requireOrganizationCompany($company);

        abort_unless($certificate->organization_company_id === $organizationCompany->id, 404);

        $this->authorize($ability, $certificate);
    }

    private function requireOrganizationCompany(Company $company): OrganizationCompany
    {
        $tenant = $this->requireTenantContext();

        $organizationCompany = OrganizationCompany::query()
            ->where('organization_id', $tenant->organizationId)
            ->where('company_id', $company->id)
            ->first();

        abort_if($organizationCompany === null, 404);

        return $organizationCompany;
    }

    private function actingMembership(): Membership
    {
        return Membership::query()->findOrFail($this->requireTenantContext()->organizationMembershipId);
    }

    /**
     * @return Collection<int, OrganizationCompanyTaxCertificate>
     */
    private function certificatesFor(OrganizationCompany $organizationCompany): Collection
    {
        return OrganizationCompanyTaxCertificate::query()
            ->where('organization_company_id', $organizationCompany->id)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get();
    }

    private function done(Company $company, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect()->to(TenantRoute::to('companies.tax-certificates.index', $company));
    }
}
