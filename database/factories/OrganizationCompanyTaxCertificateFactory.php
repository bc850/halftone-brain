<?php

namespace Database\Factories;

use App\Enums\TaxCertificateVerificationStatus;
use App\Enums\TaxExemptionCategory;
use App\Models\OrganizationCompany;
use App\Models\OrganizationCompanyTaxCertificate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationCompanyTaxCertificate>
 */
class OrganizationCompanyTaxCertificateFactory extends Factory
{
    protected $model = OrganizationCompanyTaxCertificate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_company_id' => OrganizationCompany::factory(),
            'organization_id' => fn (array $attributes): int => (int) OrganizationCompany::query()
                ->whereKey($attributes['organization_company_id'])
                ->value('organization_id'),
            'parent_account_id' => fn (array $attributes): int => (int) OrganizationCompany::query()
                ->whereKey($attributes['organization_company_id'])
                ->value('parent_account_id'),
            'exemption_category' => TaxExemptionCategory::Resale,
            'jurisdiction_state' => 'GA',
            'certificate_form_type' => 'ST-5',
            'certificate_number' => 'CERT-'.fake()->numerify('######'),
            'effective_date' => '2026-01-01',
            'expiration_date' => null,
            'verification_status' => TaxCertificateVerificationStatus::Pending,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (): array => [
            'verification_status' => TaxCertificateVerificationStatus::Verified,
            'verified_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'verification_status' => TaxCertificateVerificationStatus::Expired,
            'expiration_date' => '2026-01-31',
        ]);
    }
}
