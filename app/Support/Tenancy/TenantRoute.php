<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\Route;

/**
 * Resolves named routes to org.* equivalents when TenantContext is active.
 */
final class TenantRoute
{
    public static function name(string $legacyName): string
    {
        if (! TenantContext::has()) {
            return $legacyName;
        }

        $orgName = 'org.'.$legacyName;

        return Route::has($orgName) ? $orgName : $legacyName;
    }

    public static function to(string $legacyName, mixed ...$parameters): string
    {
        $name = self::name($legacyName);

        if (! TenantContext::has() || ! str_starts_with($name, 'org.')) {
            return route($name, ...$parameters);
        }

        $organization = TenantContext::get()->organization;
        $routeParameters = ['organization' => $organization];

        if ($parameters !== []) {
            if (is_array($parameters[0])) {
                $routeParameters += $parameters[0];
            } else {
                $routeParameters += self::resolvePositionalParameters($name, array_values($parameters));
            }
        }

        return route($name, $routeParameters);
    }

    /**
     * @param  array<int, mixed>  $parameters
     * @return array<string|int, mixed>
     */
    private static function resolvePositionalParameters(string $routeName, array $parameters): array
    {
        $route = Route::getRoutes()->getByName($routeName);

        if ($route === null) {
            return $parameters;
        }

        $parameterNames = array_values(array_filter(
            $route->parameterNames(),
            fn (string $parameterName): bool => $parameterName !== 'organization',
        ));

        /** @var array<string, mixed> $resolved */
        $resolved = [];

        foreach ($parameters as $index => $parameter) {
            if (! array_key_exists($index, $parameterNames)) {
                break;
            }

            $resolved[$parameterNames[$index]] = $parameter;
        }

        return $resolved;
    }
}
