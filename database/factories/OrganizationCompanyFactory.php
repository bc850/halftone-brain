<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Organization;
use App\Models\OrganizationCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationCompany>
 */
class OrganizationCompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'company_id' => Company::factory(),
            'lifecycle_status' => 'prospect',
            'relationship_status' => 'new',
            'tax_posture' => 'unknown',
            'is_flagged' => false,
            'credit_hold' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (OrganizationCompany $organizationCompany): void {
            if (isset($organizationCompany->attributes['parent_account_id'])) {
                return;
            }

            $organization = $organizationCompany->organization()
                ->getResults()
                ?? Organization::query()->find($organizationCompany->organization_id);

            if ($organization instanceof Organization) {
                $organizationCompany->parent_account_id = $organization->parent_account_id;
            }
        });
    }
}
