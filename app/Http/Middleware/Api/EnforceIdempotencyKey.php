<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/contracts/api-contract.md §1.3 — POST accepts an Idempotency-Key header; a
 * repeat with the same key within 24 hours returns the original response instead
 * of creating a duplicate. Keyed per caller so two different clients reusing the
 * same key value never collide.
 */
final class EnforceIdempotencyKey
{
    private const TTL_HOURS = 24;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST')) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || $key === '') {
            return $next($request);
        }

        $cacheKey = $this->cacheKey($request, $key);

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && is_int($cached['status'] ?? null) && is_array($cached['body'] ?? null)) {
            $headers = is_array($cached['headers'] ?? null) ? $cached['headers'] : [];

            return response()->json($cached['body'], $cached['status'], $headers);
        }

        $response = $next($request);

        if ($response->getStatusCode() < 500) {
            $location = $response->headers->get('Location');
            Cache::put($cacheKey, [
                'status' => $response->getStatusCode(),
                'body' => json_decode((string) $response->getContent(), true),
                'headers' => $location === null ? [] : ['Location' => $location],
            ], now()->addHours(self::TTL_HOURS));
        }

        return $response;
    }

    private function cacheKey(Request $request, string $idempotencyKey): string
    {
        $identifier = $request->user()?->getAuthIdentifier();
        $clientId = is_string($identifier) || is_int($identifier) ? (string) $identifier : 'anonymous';

        return "api:idempotency:{$clientId}:{$idempotencyKey}";
    }
}
