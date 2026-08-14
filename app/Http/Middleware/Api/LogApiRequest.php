<?php

namespace App\Http\Middleware\Api;

use App\Support\Api\ApiTrace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\Client;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs client/route/status/duration for every `/api/v1/*` call, tagged with the
 * same trace_id an error response for this request would carry (ApiTrace) — so a
 * client-reported trace_id is directly greppable in these logs.
 */
final class LogApiRequest
{
    public function __construct(private readonly ApiTrace $trace) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        $response = $next($request);

        $client = $request->attributes->get('oauth_client');

        Log::channel('api')->info('api_request', [
            'trace_id' => $this->trace->id(),
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'client_id' => $client instanceof Client ? $client->id : null,
        ]);

        return $response;
    }
}
