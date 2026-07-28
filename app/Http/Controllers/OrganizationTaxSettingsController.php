<?php

namespace App\Http\Controllers;

use App\Enums\TaxSourcingStrategy;
use App\Http\Controllers\Concerns\HandlesTaxConfiguration;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Requests\StoreOrganizationTaxRateRequest;
use App\Http\Requests\SupersedeOrganizationTaxRateRequest;
use App\Http\Requests\UpdateOrganizationTaxProfileRequest;
use App\Http\Requests\UpdateOrganizationTaxRateRequest;
use App\Http\Resources\TaxProfileResource;
use App\Http\Resources\TaxRateResource;
use App\Models\Organization;
use App\Models\OrganizationTaxProfile;
use App\Models\OrganizationTaxRate;
use App\Models\User;
use App\Support\Tax\OrganizationTaxProfileService;
use App\Support\Tax\OrganizationTaxRateManagementService;
use App\Support\Tenancy\TenantRoute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organization's tax configuration surface.
 *
 * Everything here is entered by the organization that will be taxed under it: no
 * rate is shipped as a default, and nothing on this screen is tax advice.
 */
class OrganizationTaxSettingsController extends Controller
{
    use HandlesTaxConfiguration;
    use RequiresTenantContext;

    /**
     * A configured rate is never edited into a different rate, so the UI needs to be
     * told which levers exist.
     */
    public const RATE_DISCLAIMER = 'Rates must be verified against the applicable tax authority. '
        .'Halftone Brain stores configured rates and quote snapshots; it does not provide tax advice.';

    public function __construct(
        private OrganizationTaxProfileService $profiles,
        private OrganizationTaxRateManagementService $rates,
    ) {}

    public function edit(Request $request): Response
    {
        $tenant = $this->requireTenantContext();
        $this->authorize('viewAny', OrganizationTaxProfile::class);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('tax/Settings', [
            'profile' => TaxProfileResource::make($this->profileFor($tenant->organizationId)),
            'rates' => TaxRateResource::collection($this->ratesFor($tenant->organizationId)),
            'sourcingStrategies' => array_map(
                fn (TaxSourcingStrategy $strategy): array => [
                    'value' => $strategy->value,
                    'label' => $strategy->label(),
                ],
                TaxSourcingStrategy::cases(),
            ),
            'canManage' => $user->can('create', OrganizationTaxProfile::class),
            'disclaimer' => self::RATE_DISCLAIMER,
        ]);
    }

    public function updateProfile(UpdateOrganizationTaxProfileRequest $request): RedirectResponse
    {
        $tenant = $this->requireTenantContext();

        $existing = $this->profileFor($tenant->organizationId);
        $changes = $request->profileChanges();

        if ($existing !== null) {
            $this->authorize('update', $existing);
        }

        $this->runTaxConfigurationMutation(function () use ($existing, $changes, $request, $tenant): void {
            if ($existing === null) {
                $created = $this->profiles->create(
                    organization: $tenant->organization,
                    defaultCountry: $changes['default_country'],
                    defaultState: $changes['default_state'],
                    sourcingStrategy: $changes['sourcing_strategy'],
                    registrationReference: $changes['registration_reference'],
                    taxCalculationEnabled: $changes['tax_calculation_enabled'],
                    actor: $request->user(),
                );

                // A profile is born active; retiring it at creation is unusual but
                // legitimate, and only the service may move the flag.
                if (! $changes['is_active']) {
                    $this->profiles->setActive($created, false, $request->user());
                }

                return;
            }

            $this->profiles->update($existing, $changes, $request->user());
        }, 'profile');

        return $this->done(__('Tax profile saved.'));
    }

    public function storeRate(StoreOrganizationTaxRateRequest $request): RedirectResponse
    {
        $tenant = $this->requireTenantContext();
        $this->authorize('create', OrganizationTaxRate::class);

        $data = $request->validated();

        $this->runTaxConfigurationMutation(fn (): OrganizationTaxRate => $this->rates->create(
            organization: $tenant->organization,
            jurisdictionCode: (string) $data['jurisdiction_code'],
            displayName: (string) $data['display_name'],
            ratePercent: $request->ratePercent(),
            effectiveFrom: (string) $data['effective_from'],
            effectiveThrough: $data['effective_through'] ?? null,
            country: (string) $data['country'],
            state: $data['state'] ?? null,
            county: $data['county'] ?? null,
            city: $data['city'] ?? null,
            postalCode: $data['postal_code'] ?? null,
            sourceNote: $data['source_note'] ?? null,
            actor: $request->user(),
        ), 'rate');

        return $this->done(__('Tax rate added.'));
    }

    public function updateRate(
        UpdateOrganizationTaxRateRequest $request,
        ?Organization $organization,
        OrganizationTaxRate $taxRate,
    ): RedirectResponse {
        $this->requireTenantContext();
        $this->authorize('update', $taxRate);

        $this->runTaxConfigurationMutation(fn (): OrganizationTaxRate => $this->rates->update(
            rate: $taxRate,
            data: $request->rateChanges(),
            actor: $request->user(),
        ), 'rate');

        return $this->done(__('Tax rate updated.'));
    }

    public function deactivateRate(
        Request $request,
        ?Organization $organization,
        OrganizationTaxRate $taxRate,
    ): RedirectResponse {
        $this->requireTenantContext();
        $this->authorize('deactivate', $taxRate);

        $this->runTaxConfigurationMutation(fn (): OrganizationTaxRate => $this->rates->deactivate(
            rate: $taxRate,
            actor: $request->user(),
        ), 'rate');

        return $this->done(__('Tax rate deactivated.'));
    }

    public function supersedeRate(
        SupersedeOrganizationTaxRateRequest $request,
        ?Organization $organization,
        OrganizationTaxRate $taxRate,
    ): RedirectResponse {
        $this->requireTenantContext();
        $this->authorize('update', $taxRate);

        $this->runTaxConfigurationMutation(fn (): OrganizationTaxRate => $this->rates->supersede(
            rate: $taxRate,
            newRatePercent: $request->ratePercent(),
            effectiveFrom: (string) $request->validated('effective_from'),
            sourceNote: $request->validated('source_note'),
            actor: $request->user(),
        ), 'rate');

        return $this->done(__('Tax rate superseded.'));
    }

    private function profileFor(int $organizationId): ?OrganizationTaxProfile
    {
        return OrganizationTaxProfile::query()
            ->where('organization_id', $organizationId)
            ->first();
    }

    /**
     * @return Collection<int, OrganizationTaxRate>
     */
    private function ratesFor(int $organizationId): Collection
    {
        return OrganizationTaxRate::query()
            ->where('organization_id', $organizationId)
            ->orderBy('jurisdiction_code')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect()->to(TenantRoute::to('tax-settings.edit'));
    }
}
