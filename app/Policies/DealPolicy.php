<?php

namespace App\Policies;

use App\Models\Deal;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithTenant;

class DealPolicy
{
    use AuthorizesWithTenant;

    public function viewAny(User $user): bool
    {
        if ($this->inTenant()) {
            return $this->tenant()->canOrg('crm.deal.view')
                || $this->tenant()->canOrg('crm.deal.view_all');
        }

        return true;
    }

    public function view(User $user, Deal $deal): bool
    {
        if ($this->inTenant()) {
            if (! $this->dealInCurrentOrganization($deal)) {
                return false;
            }

            if ($this->tenant()->canOrg('crm.deal.view_all')) {
                return true;
            }

            return $this->tenant()->canOrg('crm.deal.view') && $this->ownsDeal($user, $deal);
        }

        return $user->canSeeEveryone() || $deal->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        if ($this->inTenant()) {
            return $this->tenant()->canOrg('crm.deal.create');
        }

        return $user->isAdmin() || $user->isSalesman();
    }

    public function update(User $user, Deal $deal): bool
    {
        if ($this->inTenant()) {
            if (! $this->dealInCurrentOrganization($deal) || ! $this->tenant()->canOrg('crm.deal.update')) {
                return false;
            }

            if ($this->tenant()->canOrg('crm.deal.view_all') || $this->tenant()->canOrg('crm.deal.reassign')) {
                return true;
            }

            return $this->ownsDeal($user, $deal);
        }

        return $user->isAdmin() || $deal->owner_id === $user->id;
    }

    public function delete(User $user, Deal $deal): bool
    {
        if ($this->inTenant()) {
            if (! $this->dealInCurrentOrganization($deal) || ! $this->tenant()->canOrg('crm.deal.delete')) {
                return false;
            }

            return $this->tenant()->canOrg('crm.deal.view_all') || $this->ownsDeal($user, $deal);
        }

        return $user->isAdmin() || $deal->owner_id === $user->id;
    }

    public function reassign(User $user, Deal $deal): bool
    {
        if ($this->inTenant()) {
            return $this->dealInCurrentOrganization($deal)
                && $this->tenant()->canOrg('crm.deal.reassign');
        }

        return $user->isAdmin();
    }
}
