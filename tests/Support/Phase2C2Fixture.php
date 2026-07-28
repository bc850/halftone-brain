<?php

namespace Tests\Support;

use App\Enums\DealStage;
use App\Enums\PricingMethod;
use App\Enums\TaxExemptionCategory;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\OrganizationCompany;
use App\Models\OrganizationCompanyTaxCertificate;
use App\Models\OrganizationProduct;
use App\Models\OrganizationTaxProfile;
use App\Models\OrganizationTaxRate;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionLineItem;
use App\Support\Quotes\QuoteDraftLineService;
use App\Support\Quotes\QuoteFactoryService;
use App\Support\Tax\OrganizationCompanyTaxCertificateService;
use App\Support\Tax\OrganizationTaxProfileService;
use App\Support\Tax\OrganizationTaxRateManagementService;
use App\Support\Tenancy\PermissionResolver;
use App\Support\Tenancy\TenantContext;

/**
 * Shared setup for the Phase 2C.2 tax and approval service tests.
 *
 * This lives in a class rather than in file-local helpers so that any one of the four
 * test files can be run on its own.
 */
final class Phase2C2Fixture
{
    /**
     * A tenant with a quote whose draft revision already carries one taxable catalog
     * line, so a taxable basis exists without every test rebuilding one.
     *
     * @return array{
     *     ctx: array<string, mixed>,
     *     quote: Quote,
     *     revision: QuoteRevision,
     *     organizationCompany: OrganizationCompany,
     *     deal: Deal
     * }
     */
    public static function draftQuote(
        string $orgRole = 'owner',
        int $lineUnitPriceCents = 100_000,
        string $quantity = '1',
    ): array {
        $ctx = createTenantUser($orgRole);

        $deal = Deal::factory()->create([
            'organization_id' => $ctx['organization']->id,
            'parent_account_id' => $ctx['parent']->id,
            'owner_id' => $ctx['user']->id,
        ]);

        $quote = app(QuoteFactoryService::class)->create(
            deal: $deal,
            createdByMembership: $ctx['membership'],
            organization: $ctx['organization'],
            quotePrefix: '2C2-Q-',
            salesOwnerMembership: $ctx['membership'],
            actor: $ctx['user'],
        );

        $revision = $quote->currentRevision;

        if ($lineUnitPriceCents > 0) {
            self::addTaxableLine($ctx, $quote, $revision, $lineUnitPriceCents, $quantity);
        }

        return [
            'ctx' => $ctx,
            'quote' => $quote->fresh() ?? $quote,
            'revision' => $revision->fresh() ?? $revision,
            'organizationCompany' => OrganizationCompany::query()->findOrFail($deal->organization_company_id),
            'deal' => $deal->fresh() ?? $deal,
        ];
    }

    /**
     * Put the given tenant user in scope so policies can be asked directly.
     *
     * @param  array<string, mixed>  $ctx
     */
    public static function establishTenant(array $ctx): void
    {
        TenantContext::clear();

        $resolver = app(PermissionResolver::class);

        TenantContext::establish(
            userId: $ctx['user']->id,
            parentAccountId: $ctx['parent']->id,
            organizationId: $ctx['organization']->id,
            parentMembershipId: $ctx['parentMembership']?->id,
            organizationMembershipId: $ctx['membership']->id,
            organization: $ctx['organization'],
            parentPermissions: $resolver->forParentMembership($ctx['parentMembership']),
            organizationPermissions: $resolver->forOrganizationMembership($ctx['membership']),
        );
    }

    /**
     * Make the customer an established one so the new-customer trigger stops firing.
     */
    public static function makeCustomerEstablished(OrganizationCompany $organizationCompany): void
    {
        $organizationCompany->forceFill([
            'relationship_status' => 'active',
            'is_flagged' => false,
        ])->save();

        Deal::factory()->create([
            'organization_id' => $organizationCompany->organization_id,
            'parent_account_id' => $organizationCompany->parent_account_id,
            'company_id' => $organizationCompany->company_id,
            'organization_company_id' => $organizationCompany->id,
            'stage' => DealStage::QuoteWon,
        ]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public static function addTaxableLine(
        array $ctx,
        Quote $quote,
        QuoteRevision $revision,
        int $unitPriceCents,
        string $quantity = '1',
        bool $isTaxable = true,
    ): QuoteRevisionLineItem {
        $organizationProduct = OrganizationProduct::factory()->create([
            'organization_id' => $ctx['organization']->id,
            'parent_account_id' => $ctx['parent']->id,
            'pricing_method' => PricingMethod::Fixed,
            'fixed_price_cents' => $unitPriceCents,
            'allow_price_override' => true,
        ]);

        $revision = $revision->fresh() ?? $revision;

        return app(QuoteDraftLineService::class)->addCatalogLine(
            quote: $quote->fresh() ?? $quote,
            revision: $revision,
            expectedRevisionLockVersion: $revision->lock_version,
            organizationProduct: $organizationProduct,
            quantity: $quantity,
            isTaxable: $isTaxable,
            actor: $ctx['user'],
        );
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $overrides
     */
    public static function taxProfile(array $ctx, array $overrides = []): OrganizationTaxProfile
    {
        /** @var Organization $organization */
        $organization = $ctx['organization'];

        $profile = app(OrganizationTaxProfileService::class)->create(
            organization: $organization,
            defaultState: 'GA',
            actor: $ctx['user'],
        );

        if ($overrides === []) {
            return $profile;
        }

        return app(OrganizationTaxProfileService::class)->update($profile, $overrides, $ctx['user']);
    }

    /**
     * An 8% Fulton County rate, effective from well before any test quote.
     *
     * @param  array<string, mixed>  $ctx
     */
    public static function taxRate(
        array $ctx,
        string $ratePercent = '8',
        string $jurisdictionCode = 'us-ga-fulton',
        string $effectiveFrom = '2020-01-01',
        ?string $effectiveThrough = null,
        string $state = 'GA',
    ): OrganizationTaxRate {
        /** @var Organization $organization */
        $organization = $ctx['organization'];

        return app(OrganizationTaxRateManagementService::class)->create(
            organization: $organization,
            jurisdictionCode: $jurisdictionCode,
            displayName: 'Fulton County, GA',
            ratePercent: $ratePercent,
            effectiveFrom: $effectiveFrom,
            effectiveThrough: $effectiveThrough,
            state: $state,
            sourceNote: 'Entered for testing; not a legal rate.',
            actor: $ctx['user'],
        );
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public static function certificate(
        array $ctx,
        OrganizationCompany $organizationCompany,
        string $jurisdictionState = 'GA',
        string $effectiveDate = '2020-01-01',
        ?string $expirationDate = null,
        ?string $evidenceReference = 'files/exemption/ga-1234.pdf',
        TaxExemptionCategory $category = TaxExemptionCategory::Resale,
    ): OrganizationCompanyTaxCertificate {
        return app(OrganizationCompanyTaxCertificateService::class)->create(
            organizationCompany: $organizationCompany,
            exemptionCategory: $category,
            jurisdictionState: $jurisdictionState,
            certificateFormType: 'ST-5',
            effectiveDate: $effectiveDate,
            certificateNumber: 'CERT-SECRET-9911',
            evidenceReference: $evidenceReference,
            expirationDate: $expirationDate,
            actor: $ctx['user'],
        );
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public static function verifiedCertificate(
        array $ctx,
        OrganizationCompany $organizationCompany,
        string $jurisdictionState = 'GA',
    ): OrganizationCompanyTaxCertificate {
        $certificate = self::certificate($ctx, $organizationCompany, $jurisdictionState);

        return app(OrganizationCompanyTaxCertificateService::class)->verify(
            certificate: $certificate,
            verifiedBy: $ctx['membership'],
            actor: $ctx['user'],
        );
    }
}
