<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/contracts/api-contract.md §1.6 — every GET response carries an ETag;
 * a repeat with If-None-Match returns 304 with an empty body.
 *
 * A single-record show() computes its own ETag tied to the record's identity and
 * updated_at (so update()'s If-Match check can compare against the exact same
 * value) — this middleware must not clobber that with a body-hash ETag computed
 * after the fact; it only fills in an ETag for responses that don't have one yet.
 */
final class SetETag
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethod('GET') || ! $response instanceof JsonResponse) {
            return $response;
        }

        $etag = $response->headers->get('ETag') ?? '"'.md5((string) $response->getContent()).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response()->noContent(304, ['ETag' => $etag]);
        }

        $response->headers->set('ETag', $etag);

        return $response;
    }
}
