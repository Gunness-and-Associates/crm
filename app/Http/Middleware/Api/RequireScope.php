<?php

namespace App\Http\Middleware\Api;

use App\Exceptions\Api\ApiException;
use App\Support\Api\ApiScopes;
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
        $module = $request->route('module');
        $module = is_string($module) ? $module : '';

        $action = match ($request->method()) {
            'POST', 'PATCH', 'PUT' => 'write',
            'DELETE' => 'delete',
            default => 'read',
        };

        return ApiScopes::for($module, $action);
    }
}
