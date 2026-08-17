<?php

namespace App\Http\Middleware\Api;

use App\Support\Settings;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/contracts/api-contract.md Part 3 — `POST /api/v1/ingest/wordpress` carries
 * `X-Api-Key`, checked against a key stored via Settings (never `.env`), never a
 * bearer OAuth token — this is a separate, simpler auth scheme for a machine
 * (WordPress) that can't run an OAuth2 client-credentials flow itself.
 */
final class VerifyApiKey
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $settingsKey): Response
    {
        $expected = $this->settings->get($settingsKey);
        $provided = $request->header('X-Api-Key');

        if (! is_string($expected) || $expected === '' || ! is_string($provided) || ! hash_equals($expected, $provided)) {
            throw new AuthenticationException('Missing or invalid X-Api-Key.');
        }

        return $next($request);
    }
}
