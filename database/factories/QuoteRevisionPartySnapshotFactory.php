<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Membership;
use App\Models\OrganizationCompany;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionPartySnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuoteRevisionPartySnapshot>
 */
class QuoteRevisionPartySnapshotFactory extends Factory
{
    protected $model = QuoteRevisionPartySnapshot::class;

    /**
     * Prefer {@see createForRevision()} — bare create() is for schema smoke only.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'selling_organization_name' => 'Temp',
            'selling_organization_slug' => 'temp',
            'customer_company_name' => 'Temp Customer',
            'preparer_name' => 'Preparer',
            'preparer_email' => 'preparer@example.com',
        ];
    }

    public static function createForRevision(QuoteRevision $revision, ?Membership $preparer = null): QuoteRevisionPartySnapshot
    {
        $quote = $revision->quote()->firstOrFail();
        $organization = $quote->organization()->firstOrFail();
        $orgCompany = OrganizationCompany::query()->findOrFail($quote->organization_company_id);
        $company = Company::query()->findOrFail($orgCompany->company_id);
        $preparer ??= Membership::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $user = User::query()->findOrFail($preparer->user_id);

        return QuoteRevisionPartySnapshot::query()->create([
            'parent_account_id' => $revision->parent_account_id,
            'organization_id' => $revision->organization_id,
            'quote_id' => $revision->quote_id,
            'quote_revision_id' => $revision->id,
            'selling_organization_name' => $organization->name,
            'selling_organization_slug' => $organization->slug,
            'selling_organization_display_json' => [
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'company_id' => $company->id,
            'customer_company_name' => $company->name,
            'organization_company_id' => $orgCompany->id,
            'customer_number' => $orgCompany->customer_number,
            'primary_contact_id' => null,
            'preparer_membership_id' => $preparer->id,
            'preparer_user_id' => $user->id,
            'preparer_name' => $user->name,
            'preparer_email' => $user->email,
        ]);
    }
}
