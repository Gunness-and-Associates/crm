<?php

namespace App\Http\Middleware\Api;

use App\Exceptions\Api\ApiException;
use App\Support\Api\ApiModuleRegistry;
use App\Support\Api\ApiScopes;
use App\Support\LegacyApi\LegacyModuleAlias;
use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\Contracts\ScopeAuthorizable;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/contracts/api-contract.md §1.1 — a token without the required scope gets 403
 * insufficient_scope. Registered two ways: with a literal scope argument for routes
 * with no `{module}` (meta/*), or bare for the module-resource routes, where the
 * required scope depends on the route's own {module} parameter and can't be known
 * until request time — Passport's own `scope:` middleware only handles a fixed string.
 *
 * Checked against the raw token (set by AuthenticateApiToken as the
 * `oauth_access_token` request attribute), not `$request->user()->tokenCan()` — a
 * client-credentials token whose client has no `owner` has no resolvable user, but
 * its own granted scopes are still checkable directly.
 *
 * Shared with the legacy `/Api/V8/*` adapter (Z-5.5) too — a scope is always named
 * after the *canonical* v1 module key, never the legacy alias, so a legacy request
 * (whose module comes from a `{legacyModule}` route param, or from `data.type` in
 * the body for the type-in-body write routes) is translated before building the
 * scope string.
 */
final class RequireScope
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $staticScope = null): Response
    {
        $scope = $staticScope ?? $this->scopeForRequest($request);
        $token = $request->attributes->get('oauth_access_token');

        if (! $token instanceof ScopeAuthorizable || $token->cant($scope)) {
            throw ApiException::insufficientScope("This token does not have the [{$scope}] scope.");
        }

        return $next($request);
    }

    private function scopeForRequest(Request $request): string
    {
        $module = $this->moduleForRequest($request);

        $action = match ($request->method()) {
            'POST', 'PATCH', 'PUT' => 'write',
            'DELETE' => 'delete',
            default => 'read',
        };

        return ApiScopes::for($module, $action);
    }

    private function moduleForRequest(Request $request): string
    {
        $module = $request->route('module');
        if (! is_string($module)) {
            $module = $request->route('legacyModule');
        }
        if (! is_string($module)) {
            $module = $request->input('data.type');
        }
        $module = is_string($module) ? $module : '';

        if ($module === '' || app(ApiModuleRegistry::class)->exists($module)) {
            return $module;
        }

        try {
            return app(LegacyModuleAlias::class)->resolve($module)->module;
        } catch (ApiException) {
            // Not a recognised legacy alias either — return as-is; the controller
            // raises its own clearer 404/410 once execution gets that far.
            return $module;
        }
    }
}
