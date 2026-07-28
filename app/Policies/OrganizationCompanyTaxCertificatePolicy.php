<?php

namespace App\Policies;

use App\Models\OrganizationCompany;
use App\Models\OrganizationCompanyTaxCertificate;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithTenant;

/**
 * Exemption certificates are customer tax documents, so reading them and deciding on
 * them are separate authorities: `crm.tax_certificate.view` to look, and
 * `crm.tax_certificate.manage` to record, edit, verify, reject, or revoke.
 *
 * Certificate numbers are sensitive even to someone who may view the record, so
 * projections — not this policy — decide which columns reach a response.
 */
class OrganizationCompanyTaxCertificatePolicy
{
    use AuthorizesWithTenant;

    public function viewAny(User $user): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->tenant()->canOrg('crm.tax_certificate.view')
            || $this->tenant()->canOrg('crm.tax_certificate.manage');
    }

    public function view(User $user, OrganizationCompanyTaxCertificate $certificate): bool
    {
        return $this->certificateInCurrentOrganization($certificate) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->tenant()->canOrg('crm.tax_certificate.manage');
    }

    public function createFor(User $user, OrganizationCompany $organizationCompany): bool
    {
        $tenant = $this->tenant();

        return $tenant !== null
            && $organizationCompany->organization_id === $tenant->organizationId
            && $this->create($user);
    }

    public function update(User $user, OrganizationCompanyTaxCertificate $certificate): bool
    {
        return $this->certificateInCurrentOrganization($certificate) && $this->create($user);
    }

    /**
     * Verifying, rejecting, revoking, and expiring are all the same authority: they are
     * the acts that decide whether evidence may be relied on.
     */
    public function decide(User $user, OrganizationCompanyTaxCertificate $certificate): bool
    {
        return $this->update($user, $certificate);
    }

    protected function certificateInCurrentOrganization(OrganizationCompanyTaxCertificate $certificate): bool
    {
        $tenant = $this->tenant();

        return $tenant !== null
            && $certificate->organization_id === $tenant->organizationId
            && $certificate->parent_account_id === $tenant->parentAccountId;
    }
}
