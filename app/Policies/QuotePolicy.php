<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithTenant;

class QuotePolicy
{
    use AuthorizesWithTenant;

    public function viewAny(User $user): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->tenant()->canOrg('crm.quote.view')
            || $this->tenant()->canOrg('crm.quote.view_all');
    }

    public function view(User $user, Quote $quote): bool
    {
        if (! $this->inTenant() || ! $this->quoteInCurrentOrganization($quote)) {
            return false;
        }

        if ($this->tenant()->canOrg('crm.quote.view_all')) {
            return true;
        }

        return $this->tenant()->canOrg('crm.quote.view') && $this->ownsQuote($user, $quote);
    }

    public function create(User $user): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->tenant()->canOrg('crm.quote.create');
    }

    public function update(User $user, Quote $quote): bool
    {
        if (! $this->inTenant() || ! $this->quoteInCurrentOrganization($quote)) {
            return false;
        }

        if (! $this->tenant()->canOrg('crm.quote.update')) {
            return false;
        }

        if ($this->tenant()->canOrg('crm.quote.view_all')) {
            return true;
        }

        return $this->ownsQuote($user, $quote);
    }

    public function void(User $user, Quote $quote): bool
    {
        if (! $this->inTenant() || ! $this->quoteInCurrentOrganization($quote)) {
            return false;
        }

        if (! $this->tenant()->canOrg('crm.quote.void')) {
            return false;
        }

        if ($this->tenant()->canOrg('crm.quote.view_all')) {
            return true;
        }

        return $this->ownsQuote($user, $quote);
    }

    protected function quoteInCurrentOrganization(Quote $quote): bool
    {
        $tenant = $this->tenant();

        return $tenant !== null && $quote->organization_id === $tenant->organizationId;
    }

    protected function ownsQuote(User $user, Quote $quote): bool
    {
        $quote->loadMissing(['salesOwnerMembership', 'createdByMembership', 'deal']);

        if ($quote->salesOwnerMembership?->user_id === $user->id) {
            return true;
        }

        if ($quote->createdByMembership?->user_id === $user->id) {
            return true;
        }

        return $quote->deal?->owner_id === $user->id;
    }
}
