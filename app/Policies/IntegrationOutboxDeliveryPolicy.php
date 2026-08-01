<?php

namespace App\Policies;

use App\Models\IntegrationOutbox;
use App\Models\IntegrationOutboxDelivery;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithTenant;

class IntegrationOutboxDeliveryPolicy
{
    use AuthorizesWithTenant;

    public function viewAny(User $user): bool
    {
        return $this->canViewOutbox();
    }

    public function view(User $user, IntegrationOutboxDelivery $delivery): bool
    {
        return $this->canViewOutbox() && $this->belongsToCurrentTenant($delivery);
    }

    public function viewOutbox(User $user, IntegrationOutbox $outbox): bool
    {
        return $this->canViewOutbox() && $this->outboxBelongsToCurrentTenant($outbox);
    }

    public function replay(User $user, IntegrationOutboxDelivery $delivery): bool
    {
        return $this->inTenant()
            && $this->tenant()?->canOrg('integrations.outbox.replay') === true
            && $this->belongsToCurrentTenant($delivery);
    }

    public function abandon(User $user, IntegrationOutboxDelivery $delivery): bool
    {
        return $this->inTenant()
            && $this->tenant()?->canOrg('integrations.outbox.abandon') === true
            && $this->belongsToCurrentTenant($delivery);
    }

    private function canViewOutbox(): bool
    {
        return $this->inTenant()
            && $this->tenant()?->canOrg('integrations.outbox.view') === true;
    }

    private function belongsToCurrentTenant(IntegrationOutboxDelivery $delivery): bool
    {
        $tenant = $this->tenant();

        return $tenant !== null
            && (int) $delivery->organization_id === $tenant->organizationId
            && (int) $delivery->parent_account_id === $tenant->parentAccountId;
    }

    private function outboxBelongsToCurrentTenant(IntegrationOutbox $outbox): bool
    {
        $tenant = $this->tenant();

        return $tenant !== null
            && (int) $outbox->organization_id === $tenant->organizationId
            && (int) $outbox->parent_account_id === $tenant->parentAccountId;
    }
}
