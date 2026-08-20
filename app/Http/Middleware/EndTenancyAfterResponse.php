<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Z-8.3 -- stancl/tenancy's identification middleware never reverts tenancy
 * itself (that's left to the app to wire up). Without this, a PHP-FPM
 * request-per-process deployment never notices, but anything that reuses one
 * process across multiple units of work (Octane, queue workers, and this
 * app's own test suite) would carry tenant #1's initialized state -- default
 * DB connection, storage_path(), cache tag -- into whatever runs next in that
 * same process. Harmless in effect while there is only one tenant, but wrong
 * to leave unreverted, and the tenant-agnostic pieces (test query-log capture,
 * anything assuming a stable default connection across a request boundary)
 * already break on it today.
 *
 * Detected by Illuminate\Foundation\Http\Kernel::terminateMiddleware() via a
 * plain method_exists() check -- Laravel 11 has no TerminableMiddleware
 * interface to implement, "having a terminate() method" is the whole contract.
 */
final class EndTenancyAfterResponse
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        tenancy()->end();
    }
}
