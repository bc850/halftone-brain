<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Contact $contact): bool
    {
        $contact->loadMissing('company');

        return $user->canSeeEveryone() || $contact->company->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isSalesman();
    }

    public function update(User $user, Contact $contact): bool
    {
        $contact->loadMissing('company');

        return $user->isAdmin() || $contact->company->owner_id === $user->id;
    }

    public function delete(User $user, Contact $contact): bool
    {
        $contact->loadMissing('company');

        return $user->isAdmin() || $contact->company->owner_id === $user->id;
    }
}
