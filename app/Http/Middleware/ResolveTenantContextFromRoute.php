<?php

namespace App\Http\Middleware;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\ParentAccountMembership;
use App\Models\User;
use App\Support\Tenancy\PermissionResolver;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantContextFromRoute
{
    public function __construct(private PermissionResolver $permissionResolver) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        $slug = $request->route('organization');

        if ($slug instanceof Organization) {
            $slug = $slug->slug;
        }

        if (! is_string($slug) || $slug === '') {
            abort(404);
        }

        $organization = Organization::query()
            ->with('parentAccount')
            ->where('slug', $slug)
            ->first();

        if ($organization === null || ! $organization->is_active) {
            abort(404);
        }

        $parent = $organization->parentAccount;

        if ($parent === null || ! $parent->is_active) {
            abort(404);
        }

        $membership = Membership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->first();

        if ($membership === null) {
            abort(404);
        }

        if ($membership->status !== MembershipStatus::Active) {
            abort(403);
        }

        $parentMembership = ParentAccountMembership::query()
            ->where('parent_account_id', $parent->id)
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active)
            ->first();

        $organizationPermissions = $this->permissionResolver->forOrganizationMembership($membership);
        $parentPermissions = $this->permissionResolver->forParentMembership($parentMembership);

        TenantContext::establish(
            userId: $user->id,
            parentAccountId: $parent->id,
            organizationId: $organization->id,
            parentMembershipId: $parentMembership?->id,
            organizationMembershipId: $membership->id,
            organization: $organization,
            parentPermissions: $parentPermissions,
            organizationPermissions: $organizationPermissions,
        );

        $request->session()->put('last_organization_id', $organization->id);

        return $next($request);
    }
}
