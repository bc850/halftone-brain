<?php

namespace App\Policies;

use App\Models\Deal;
use App\Models\User;

class DealPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Deal $deal): bool
    {
        return $user->canSeeEveryone() || $deal->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isSalesman();
    }

    public function update(User $user, Deal $deal): bool
    {
        return $user->isAdmin() || $deal->owner_id === $user->id;
    }

    public function delete(User $user, Deal $deal): bool
    {
        return $user->isAdmin() || $deal->owner_id === $user->id;
    }

    public function reassign(User $user, Deal $deal): bool
    {
        return $user->isAdmin();
    }
}
