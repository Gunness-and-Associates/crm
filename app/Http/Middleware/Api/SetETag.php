<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/contracts/api-contract.md §1.6 — every GET response carries an ETag;
 * a repeat with If-None-Match returns 304 with an empty body.
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

        $etag = '"'.md5((string) $response->getContent()).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response()->noContent(304, ['ETag' => $etag]);
        }

        $response->headers->set('ETag', $etag);

        return $response;
    }
}
