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
        return $this->permits($user, $quote, 'crm.quote.update');
    }

    public function void(User $user, Quote $quote): bool
    {
        return $this->permits($user, $quote, 'crm.quote.void');
    }

    /**
     * Deciding an approval request. Approving one's own quote is allowed and recorded as
     * a self-approval; the permission is what gates it, not who wrote the quote.
     */
    public function approve(User $user, Quote $quote): bool
    {
        return $this->permits($user, $quote, 'crm.quote.approve');
    }

    /**
     * Reaching the approval queue at all. What actually appears in it is still
     * narrowed quote by quote through `approve`.
     */
    public function approveAny(User $user): bool
    {
        if (! $this->inTenant()) {
            return false;
        }

        return $this->tenant()->canOrg('crm.quote.approve');
    }

    /**
     * Resolving tax from a configured rate is part of preparing the quote, so it needs
     * the same reach as editing it.
     */
    public function calculateTax(User $user, Quote $quote): bool
    {
        if (! $this->update($user, $quote)) {
            return false;
        }

        return $this->tenant()->canOrg('crm.quote.tax_calculate')
            || $this->tenant()->canOrg('crm.quote.tax_override');
    }

    /**
     * Replacing a calculated figure with a manual one is a separate, narrower authority.
     */
    public function overrideTax(User $user, Quote $quote): bool
    {
        if (! $this->update($user, $quote)) {
            return false;
        }

        return $this->tenant()->canOrg('crm.quote.tax_override');
    }

    /**
     * Reaching an approved revision's customer document generation.
     */
    public function generateDocument(User $user, Quote $quote): bool
    {
        return $this->update($user, $quote);
    }

    /**
     * Preparing customer links and recording manual delivery / send.
     */
    public function send(User $user, Quote $quote): bool
    {
        return $this->permits($user, $quote, 'crm.quote.send');
    }

    /**
     * Recording an employee-entered customer acceptance or rejection.
     */
    public function recordCustomerResponse(User $user, Quote $quote): bool
    {
        return $this->permits($user, $quote, 'crm.quote.record_customer_response');
    }

    /**
     * Organization-scoped permission plus the reach to see this particular quote.
     */
    protected function permits(User $user, Quote $quote, string $permission): bool
    {
        if (! $this->inTenant() || ! $this->quoteInCurrentOrganization($quote)) {
            return false;
        }

        if (! $this->tenant()->canOrg($permission)) {
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
