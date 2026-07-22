<?php

namespace App\Http\Middleware;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'email_verified_at' => $request->user()->email_verified_at,
                    'role' => $request->user()->role->value,
                    'see_everyone' => $request->user()->see_everyone,
                    'created_at' => $request->user()->created_at,
                    'updated_at' => $request->user()->updated_at,
                ] : null,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'tenant' => fn () => $this->resolveTenantProps($request),
        ];
    }

    /**
     * @return array{
     *     organization: array{id: int, name: string, slug: string}|null,
     *     permissions: list<string>,
     *     parentPermissions: list<string>,
     *     canManageParent: bool,
     *     organizations: list<array{id: int, name: string, slug: string}>
     * }
     */
    protected function resolveTenantProps(Request $request): array
    {
        $empty = [
            'organization' => null,
            'permissions' => [],
            'parentPermissions' => [],
            'canManageParent' => false,
            'organizations' => [],
        ];

        $user = $request->user();

        if ($user === null) {
            return $empty;
        }

        $organizations = array_values(Membership::query()
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active)
            ->whereHas('organization', fn ($query) => $query->where('is_active', true))
            ->with('organization:id,name,slug')
            ->get()
            ->map(fn (Membership $membership): array => [
                'id' => $membership->organization->id,
                'name' => $membership->organization->name,
                'slug' => $membership->organization->slug,
            ])
            ->all());

        if (! TenantContext::has()) {
            return [
                ...$empty,
                'organizations' => $organizations,
            ];
        }

        $tenant = TenantContext::get();
        $parentPermissions = $tenant->parentPermissions;

        return [
            'organization' => $tenant->organizationSummary(),
            'permissions' => $tenant->organizationPermissions,
            'parentPermissions' => $parentPermissions,
            'canManageParent' => collect($parentPermissions)->contains(
                fn (string $permission): bool => str_starts_with($permission, 'parent.'),
            ),
            'organizations' => $organizations,
        ];
    }
}
