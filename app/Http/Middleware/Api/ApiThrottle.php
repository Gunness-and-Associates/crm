<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/contracts/api-contract.md §1.6 — "every response carries X-RateLimit-Limit,
 * X-RateLimit-Remaining, X-RateLimit-Reset". Laravel's own ThrottleRequests only adds
 * X-RateLimit-Reset on the 429 (via its $retryAfter branch in getHeaders()) — every
 * other response is missing it. This override adds it unconditionally, reusing the
 * parent's own key/limit resolution untouched.
 */
final class ApiThrottle extends ThrottleRequests
{
    /**
     * @param  Closure(Request): Response  $next
     * @param  array<int, object{key: string, maxAttempts: int, decaySeconds: int, responseCallback: ?callable}>  $limits
     */
    protected function handleRequest($request, Closure $next, array $limits): Response
    {
        foreach ($limits as $limit) {
            if ($this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts)) {
                throw $this->buildException($request, $limit->key, $limit->maxAttempts, $limit->responseCallback);
            }

            $this->limiter->hit($limit->key, $limit->decaySeconds);
        }

        $response = $next($request);

        foreach ($limits as $limit) {
            $response = $this->addHeaders(
                $response,
                $limit->maxAttempts,
                $this->calculateRemainingAttempts($limit->key, $limit->maxAttempts),
            );

            $response->headers->set(
                'X-RateLimit-Reset',
                (string) $this->availableAt($this->limiter->availableIn($limit->key)),
            );
        }

        return $response;
    }
}
