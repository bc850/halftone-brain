<?php

namespace App\Support\Quotes;

use App\Enums\MembershipStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationCompany;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionPartySnapshot;
use App\Models\User;

/**
 * Builds the customer-visible party identity for a quote revision from live CRM rows.
 *
 * The snapshot is the display truth once written; later CRM edits never flow through
 * implicitly (see {@see QuotePartySnapshotService} for the explicit refresh path).
 */
final class QuotePartySnapshotBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function buildAttributes(
        Quote $quote,
        QuoteRevision $revision,
        Membership $preparer,
        ?Contact $primaryContact = null,
        ?Membership $salesperson = null,
        ?string $customerPoReference = null,
    ): array {
        if ($revision->quote_id !== $quote->id) {
            throw new InvalidQuoteDraftException('Revision does not belong to the given quote.');
        }

        $organization = Organization::query()->findOrFail($quote->organization_id);
        $orgCompany = OrganizationCompany::query()->findOrFail($quote->organization_company_id);
        $company = Company::query()->findOrFail($orgCompany->company_id);

        $this->assertCustomerBelongsToQuote($quote, $organization, $orgCompany, $company);
        $this->assertMembership($preparer, $organization, 'Preparer');

        if ($salesperson !== null) {
            $this->assertMembership($salesperson, $organization, 'Salesperson');
        }

        if ($primaryContact !== null) {
            $this->assertContactBelongsToCompany($primaryContact, $company);
        }

        $preparerUser = User::query()->findOrFail($preparer->user_id);
        $salespersonUser = $salesperson === null
            ? null
            : User::query()->find($salesperson->user_id);
        $contactPhone = $primaryContact === null ? null : $primaryContact->phone;

        return [
            'parent_account_id' => $quote->parent_account_id,
            'organization_id' => $quote->organization_id,
            'quote_id' => $quote->id,
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
            'primary_contact_id' => $primaryContact?->id,
            'contact_name' => $primaryContact === null ? null : $this->contactName($primaryContact),
            'contact_email' => $primaryContact?->email,
            'contact_phone' => $contactPhone ?? $company->phone,
            'billing_address_json' => $this->billingAddressJson($company),
            'service_address_json' => $this->serviceAddressJson($company),
            'salesperson_membership_id' => $salesperson?->id,
            'salesperson_user_id' => $salespersonUser?->id,
            'salesperson_name' => $salespersonUser?->name,
            'salesperson_email' => $salespersonUser?->email,
            'preparer_membership_id' => $preparer->id,
            'preparer_user_id' => $preparerUser->id,
            'preparer_name' => $preparerUser->name,
            'preparer_email' => $preparerUser->email,
            'customer_po_reference' => $customerPoReference,
        ];
    }

    public function createInitial(
        Quote $quote,
        QuoteRevision $revision,
        Membership $preparer,
        ?Contact $primaryContact = null,
        ?Membership $salesperson = null,
        ?string $customerPoReference = null,
    ): QuoteRevisionPartySnapshot {
        return QuoteRevisionPartySnapshot::query()->create($this->buildAttributes(
            $quote,
            $revision,
            $preparer,
            $primaryContact,
            $salesperson,
            $customerPoReference,
        ));
    }

    /**
     * Company holds a single mailing address pair; service address mirrors shipping.
     *
     * @return array<string, string|null>|null
     */
    public function billingAddressJson(Company $company): ?array
    {
        return $this->addressJson(
            $company->billing_address_line1,
            $company->billing_address_line2,
            $company->billing_city,
            $company->billing_state,
            $company->billing_postal_code,
            $company->billing_country,
        );
    }

    /**
     * @return array<string, string|null>|null
     */
    public function serviceAddressJson(Company $company): ?array
    {
        return $this->addressJson(
            $company->shipping_address_line1,
            $company->shipping_address_line2,
            $company->shipping_city,
            $company->shipping_state,
            $company->shipping_postal_code,
            $company->shipping_country,
        );
    }

    public function contactName(Contact $contact): string
    {
        return trim("{$contact->first_name} {$contact->last_name}");
    }

    /**
     * @return array<string, string|null>|null
     */
    private function addressJson(
        ?string $line1,
        ?string $line2,
        ?string $city,
        ?string $state,
        ?string $postalCode,
        ?string $country,
    ): ?array {
        $address = [
            'line1' => $this->trimToNull($line1),
            'line2' => $this->trimToNull($line2),
            'city' => $this->trimToNull($city),
            'state' => $this->trimToNull($state),
            'postal_code' => $this->trimToNull($postalCode),
            'country' => $this->trimToNull($country),
        ];

        if (array_filter($address, static fn (?string $value): bool => $value !== null) === []) {
            return null;
        }

        return $address;
    }

    private function trimToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function assertCustomerBelongsToQuote(
        Quote $quote,
        Organization $organization,
        OrganizationCompany $orgCompany,
        Company $company,
    ): void {
        if ($orgCompany->organization_id !== $organization->id) {
            throw new InvalidQuoteDraftException('Organization company does not belong to the quote organization.');
        }

        if ($company->parent_account_id !== $organization->parent_account_id
            || $orgCompany->parent_account_id !== $organization->parent_account_id
            || $quote->parent_account_id !== $organization->parent_account_id) {
            throw new InvalidQuoteDraftException('Customer company must belong to the quote parent account.');
        }
    }

    private function assertMembership(Membership $membership, Organization $organization, string $label): void
    {
        if ($membership->organization_id !== $organization->id) {
            throw new InvalidQuoteDraftException("{$label} membership must belong to the quote organization.");
        }

        if ($membership->status !== MembershipStatus::Active) {
            throw new InvalidQuoteDraftException("{$label} membership must be active.");
        }
    }

    private function assertContactBelongsToCompany(Contact $contact, Company $company): void
    {
        if ($contact->company_id !== $company->id) {
            throw new InvalidQuoteDraftException('Primary contact must belong to the customer company.');
        }

        if ($contact->parent_account_id !== $company->parent_account_id) {
            throw new InvalidQuoteDraftException('Primary contact must belong to the customer parent account.');
        }
    }
}
