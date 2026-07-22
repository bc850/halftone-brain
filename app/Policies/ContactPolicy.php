<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithTenant;

class ContactPolicy
{
    use AuthorizesWithTenant;

    public function viewAny(User $user): bool
    {
        if ($this->inTenant()) {
            return $this->tenant()->canOrg('crm.contact.view')
                || $this->tenant()->canOrg('crm.contact.view_all');
        }

        return true;
    }

    public function view(User $user, Contact $contact): bool
    {
        if ($this->inTenant()) {
            if (! $this->contactVisibleInCurrentOrganization($contact)) {
                return false;
            }

            if ($this->tenant()->canOrg('crm.contact.view_all')) {
                return true;
            }

            $contact->loadMissing('company');

            return $this->tenant()->canOrg('crm.contact.view')
                && $contact->company->owner_id === $user->id;
        }

        $contact->loadMissing('company');

        return $user->canSeeEveryone() || $contact->company->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        if ($this->inTenant()) {
            return $this->tenant()->canOrg('crm.contact.create');
        }

        return $user->isAdmin() || $user->isSalesman();
    }

    public function update(User $user, Contact $contact): bool
    {
        if ($this->inTenant()) {
            return $this->contactVisibleInCurrentOrganization($contact)
                && $this->tenant()->canParent('parent.contact.update');
        }

        $contact->loadMissing('company');

        return $user->isAdmin() || $contact->company->owner_id === $user->id;
    }

    public function delete(User $user, Contact $contact): bool
    {
        if ($this->inTenant()) {
            return $this->contactVisibleInCurrentOrganization($contact)
                && $this->tenant()->canOrg('crm.contact.delete')
                && $this->tenant()->canParent('parent.contact.update');
        }

        $contact->loadMissing('company');

        return $user->isAdmin() || $contact->company->owner_id === $user->id;
    }
}
