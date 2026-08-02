<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationProvider;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Requests\DisableMondayIntegrationSettingsRequest;
use App\Http\Requests\EnableMondayIntegrationSettingsRequest;
use App\Http\Requests\StoreMondayIntegrationSettingsRequest;
use App\Http\Requests\UpdateMondayIntegrationSettingsRequest;
use App\Http\Requests\ValidateMondayIntegrationSettingsRequest;
use App\Models\Organization;
use App\Models\OrganizationIntegrationSetting;
use App\Models\User;
use App\Support\Integrations\Monday\MondayConfigurationValidator;
use App\Support\Integrations\Monday\MondayOrganizationSettingsService;
use App\Support\Integrations\Monday\MondaySettingsProjection;
use App\Support\Integrations\StaleIntegrationSettingsException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class MondayIntegrationSettingsController extends Controller
{
    use RequiresTenantContext;

    public function __construct(
        private MondayOrganizationSettingsService $settingsService,
        private MondayConfigurationValidator $validator,
        private MondaySettingsProjection $projection,
    ) {}

    public function show(Request $request, ?Organization $organization = null): Response
    {
        $tenant = $this->requireTenantContext();
        $this->authorize('viewAny', OrganizationIntegrationSetting::class);

        /** @var User $user */
        $user = $request->user();
        $settings = $this->settingsForTenant($tenant->organizationId, $tenant->parentAccountId);

        if ($settings !== null) {
            $this->authorize('view', $settings);
        }

        return Inertia::render(
            'integrations/MondaySettings',
            $this->projection->page(
                settings: $settings,
                canManage: $user->can('create', OrganizationIntegrationSetting::class),
                canValidate: $settings !== null && $user->can('validate', $settings),
            ),
        );
    }

    public function store(StoreMondayIntegrationSettingsRequest $request, ?Organization $organization = null): RedirectResponse
    {
        $tenant = $this->requireTenantContext();

        try {
            $this->settingsService->create(
                organization: $tenant->organization,
                input: $request->mondaySettingsInput(),
                actor: $request->user(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['settings' => $exception->getMessage()]);
        }

        return redirect()
            ->route('org.integrations.settings.monday.show', $tenant->organization)
            ->with('success', 'Monday settings saved. Validate before enabling.');
    }

    public function update(
        UpdateMondayIntegrationSettingsRequest $request,
        Organization $organization,
        OrganizationIntegrationSetting $mondaySetting,
    ): RedirectResponse {
        $this->requireTenantContext();
        $this->assertRouteSettings($mondaySetting);

        try {
            $input = $request->mondaySettingsInput();
            unset($input['expected_lock_version']);

            $this->settingsService->update(
                settings: $mondaySetting,
                input: $input,
                expectedLockVersion: (int) $request->validated('expected_lock_version'),
                actor: $request->user(),
            );
        } catch (StaleIntegrationSettingsException $exception) {
            throw $exception;
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['settings' => $exception->getMessage()]);
        }

        return redirect()
            ->route('org.integrations.settings.monday.show', $organization)
            ->with('success', 'Monday settings updated. Revalidate before enabling.');
    }

    public function validateConfiguration(
        ValidateMondayIntegrationSettingsRequest $request,
        Organization $organization,
        OrganizationIntegrationSetting $mondaySetting,
    ): RedirectResponse {
        $this->requireTenantContext();
        $this->assertRouteSettings($mondaySetting);

        try {
            $validated = $this->validator->validate(
                settings: $mondaySetting,
                expectedLockVersion: $request->expectedLockVersion(),
                actor: $request->user(),
            );
        } catch (StaleIntegrationSettingsException $exception) {
            throw $exception;
        }

        $message = match ($validated->last_validation_status?->value) {
            'valid' => 'Monday configuration validated successfully. Enablement is still a separate step.',
            'client_not_configured' => 'Monday client is not configured yet. Settings remain disabled.',
            default => 'Monday configuration validation failed. Review the reported problem and try again.',
        };

        return redirect()
            ->route('org.integrations.settings.monday.show', $organization)
            ->with('success', $message);
    }

    public function enable(
        EnableMondayIntegrationSettingsRequest $request,
        Organization $organization,
        OrganizationIntegrationSetting $mondaySetting,
    ): RedirectResponse {
        $this->requireTenantContext();
        $this->assertRouteSettings($mondaySetting);

        try {
            $this->settingsService->enable(
                settings: $mondaySetting,
                expectedLockVersion: $request->expectedLockVersion(),
                actor: $request->user(),
            );
        } catch (StaleIntegrationSettingsException $exception) {
            throw $exception;
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['settings' => $exception->getMessage()]);
        }

        return redirect()
            ->route('org.integrations.settings.monday.show', $organization)
            ->with('success', 'Monday intake destination enabled for this organization.');
    }

    public function disable(
        DisableMondayIntegrationSettingsRequest $request,
        Organization $organization,
        OrganizationIntegrationSetting $mondaySetting,
    ): RedirectResponse {
        $this->requireTenantContext();
        $this->assertRouteSettings($mondaySetting);

        try {
            $this->settingsService->disable(
                settings: $mondaySetting,
                expectedLockVersion: $request->expectedLockVersion(),
                actor: $request->user(),
            );
        } catch (StaleIntegrationSettingsException $exception) {
            throw $exception;
        }

        return redirect()
            ->route('org.integrations.settings.monday.show', $organization)
            ->with('success', 'Monday intake destination disabled. Configuration was preserved.');
    }

    private function settingsForTenant(int $organizationId, int $parentAccountId): ?OrganizationIntegrationSetting
    {
        return OrganizationIntegrationSetting::query()
            ->where('organization_id', $organizationId)
            ->where('parent_account_id', $parentAccountId)
            ->where('provider', IntegrationProvider::Monday)
            ->first();
    }

    private function assertRouteSettings(OrganizationIntegrationSetting $settings): void
    {
        $tenant = $this->requireTenantContext();

        if (
            (int) $settings->organization_id !== $tenant->organizationId
            || (int) $settings->parent_account_id !== $tenant->parentAccountId
        ) {
            abort(404);
        }
    }
}
