<?php

namespace App\Policies;

use App\Enums\IntegrationProvider;
use App\Models\OrganizationIntegrationSetting;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithTenant;

class OrganizationIntegrationSettingPolicy
{
    use AuthorizesWithTenant;

    public function viewAny(User $user): bool
    {
        return $this->canView();
    }

    public function view(User $user, OrganizationIntegrationSetting $settings): bool
    {
        return $this->canView() && $this->belongsToCurrentTenant($settings);
    }

    public function create(User $user): bool
    {
        return $this->canManage();
    }

    public function update(User $user, OrganizationIntegrationSetting $settings): bool
    {
        return $this->canManage() && $this->belongsToCurrentTenant($settings);
    }

    public function validate(User $user, OrganizationIntegrationSetting $settings): bool
    {
        return $this->inTenant()
            && $this->tenant()?->canOrg('integrations.settings.validate') === true
            && $this->belongsToCurrentTenant($settings);
    }

    public function enable(User $user, OrganizationIntegrationSetting $settings): bool
    {
        return $this->canManage() && $this->belongsToCurrentTenant($settings);
    }

    public function disable(User $user, OrganizationIntegrationSetting $settings): bool
    {
        return $this->canManage() && $this->belongsToCurrentTenant($settings);
    }

    private function canView(): bool
    {
        return $this->inTenant()
            && $this->tenant()?->canOrg('integrations.settings.view') === true;
    }

    private function canManage(): bool
    {
        return $this->inTenant()
            && $this->tenant()?->canOrg('integrations.settings.manage') === true;
    }

    private function belongsToCurrentTenant(OrganizationIntegrationSetting $settings): bool
    {
        $tenant = $this->tenant();

        if (
            $tenant === null
            || (int) $settings->organization_id !== $tenant->organizationId
            || (int) $settings->parent_account_id !== $tenant->parentAccountId
        ) {
            return false;
        }

        return match ($settings->provider) {
            IntegrationProvider::Monday => true,
        };
    }
}
