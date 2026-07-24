<?php

namespace App\Support\Tenancy;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Resolves which organization a legacy navigation request should redirect into.
 * Never establishes TenantContext — callers must redirect to an org-prefixed URL.
 */
final class ActiveOrganizationResolver
{
    public function resolveFor(User $user, Request $request): ?Organization
    {
        $memberships = Membership::query()
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active)
            ->whereHas('organization', fn ($query) => $query->where('is_active', true)->whereHas('parentAccount', fn ($parent) => $parent->where('is_active', true)))
            ->with(['organization.parentAccount'])
            ->orderBy('id')
            ->get();

        if ($memberships->isEmpty()) {
            return null;
        }

        $sessionOrganizationId = $request->session()->get('last_organization_id');

        if (is_numeric($sessionOrganizationId)) {
            $fromSession = $memberships->first(
                fn (Membership $membership): bool => (int) $membership->organization_id === (int) $sessionOrganizationId
            );

            if ($fromSession !== null) {
                return $fromSession->organization;
            }
        }

        return $memberships->first()->organization;
    }
}
