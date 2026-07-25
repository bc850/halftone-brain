<?php

namespace App\Http\Middleware;

use App\Models\OrganizationProduct;
use App\Models\Product;
use App\Models\User;
use App\Support\Tenancy\ActiveOrganizationResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Legacy CRM/catalog boundary:
 * - mutations fail closed with 409 (no session-inferred tenant writes)
 * - safe GET navigation redirects into a validated /o/{organization}/… URL
 */
class EnforceLegacyTenantBoundary
{
    public const MESSAGE = 'An organization context is required for this action.';

    public function __construct(private ActiveOrganizationResolver $organizationResolver) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isMutation($request)) {
            return $this->rejectMutation($request);
        }

        return $this->redirectNavigation($request);
    }

    private function isMutation(Request $request): bool
    {
        return in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    private function rejectMutation(Request $request): Response
    {
        if ($request->expectsJson() || $request->header('X-Inertia') !== null) {
            return response()->json(['message' => self::MESSAGE], 409);
        }

        throw new HttpException(409, self::MESSAGE);
    }

    private function redirectNavigation(Request $request): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        $organization = $this->organizationResolver->resolveFor($user, $request);

        if ($organization === null) {
            abort(403, 'An active organization membership is required.');
        }

        $route = $request->route();
        $legacyName = $route?->getName();

        if (! is_string($legacyName) || $legacyName === '') {
            abort(404);
        }

        $orgName = 'org.'.$legacyName;

        if (! Route::has($orgName)) {
            abort(404);
        }

        $parameters = $route->parameters();
        unset($parameters['organization']);
        $parameters['organization'] = $organization;

        // Org catalog show/edit now bind OrganizationProduct, not Product Master.
        if (in_array($legacyName, ['products.show', 'products.edit'], true) && isset($parameters['product'])) {
            $product = $parameters['product'];
            $productId = $product instanceof Product ? $product->id : (int) $product;

            $organizationProduct = OrganizationProduct::query()
                ->where('organization_id', $organization->id)
                ->where('product_id', $productId)
                ->first();

            if ($organizationProduct === null) {
                abort(404);
            }

            unset($parameters['product']);
            $parameters['organizationProduct'] = $organizationProduct;

            if ($legacyName === 'products.edit') {
                $orgName = 'org.products.edit-settings';
            }
        }

        $url = route($orgName, $parameters);
        $parts = parse_url($url);
        $path = $parts['path'] ?? '/';
        $existingQuery = [];

        if (isset($parts['query'])) {
            parse_str($parts['query'], $existingQuery);
        }

        unset($existingQuery['component'], $existingQuery['version']);

        $forwardQuery = collect($request->query())
            ->except(['component', 'version'])
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->all();

        $query = array_merge($existingQuery, $forwardQuery);
        $target = $path;

        if ($query !== []) {
            $target .= '?'.http_build_query($query);
        }

        return redirect()->to($target);
    }
}
